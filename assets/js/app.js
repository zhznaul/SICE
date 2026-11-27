(() => {
  'use strict';

  // ---------------------------------------------------------------------------
  // Constantes e utilitários (idênticos ao SICE original)
  // ---------------------------------------------------------------------------
  const ROLE_LABELS = {
    owner: 'Proprietário',
    storekeeper: 'Almoxarife',
    operational: 'Equipe operacional',
  };

  const AREA_LABELS = {
    kitchen: 'Cozinha',
    bar: 'Bar',
    storekeeper: 'Almoxarife',
  };

  const CATALOG_TYPE_LABELS = {
    perishable: 'Perecível',
    'non-perishable': 'Não perecível',
    beverage: 'Bebida',
    disposable: 'Descartável',
  };

  const INVENTORY_GROUPS = [
    'Hortifrúti',
    'Carnes e pescados',
    'Laticínios e frios',
    'Bebidas',
    'Materiais descartáveis',
  ];

  const UNITS = ['kg', 'g', 'un', 'litros', 'caixa'];
  const LABEL_UNITS = ['UN', 'KG', 'G', 'L', 'ML'];
  const INVENTORY_CODE_FORMATS = ['qr_code', 'ean_13', 'ean_8', 'code_128', 'upc_a', 'upc_e'];

  const SEASONALITY = {
    regular: { label: 'Operação regular', factor: 1, note: 'Demanda dentro da média histórica.' },
    high: { label: 'Alta temporada', factor: 1.24, note: 'Verão, férias e maior fluxo turístico em Santos.' },
    holiday: { label: 'Festas e eventos', factor: 1.36, note: 'Celebrações, feriados prolongados e eventos fechados.' },
    low: { label: 'Baixa temporada', factor: 0.82, note: 'Fluxo reduzido e compras mais conservadoras.' },
  };

  const ORDER_STATUS_LABELS = {
    planned: 'Planejada',
    ordered: 'Solicitada',
    received: 'Recebida',
    cancelled: 'Cancelada',
  };

  function esc(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function icon(name, size = 18, extraClass = '', strokeWidth = null) {
    const cls = extraClass ? ` class="${esc(extraClass)}"` : '';
    const sw = strokeWidth ? ` stroke-width="${strokeWidth}"` : '';
    return `<i data-lucide="${esc(name)}" width="${size}" height="${size}"${cls}${sw}></i>`;
  }

  function money(value) {
    return new Intl.NumberFormat('pt-BR', { currency: 'BRL', style: 'currency' }).format(Number(value) || 0);
  }

  function formatDate(value) {
    if (!value) return 'Não informado';
    const iso = String(value).length === 10 ? `${value}T00:00:00Z` : value;
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return 'Não informado';
    return d.toLocaleDateString('pt-BR', { timeZone: 'UTC' });
  }

  function formatLocalDate(value) {
    if (!value) return '—';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleDateString('pt-BR');
  }

  function isLowStock(product) {
    return product.active !== false && Number(product.quantity) <= Number(product.minimumStock);
  }

  function expiresSoon(product) {
    if (product.active === false) return false;
    if (product.expiryDate) {
      const iso = String(product.expiryDate).length === 10 ? `${product.expiryDate}T00:00:00Z` : product.expiryDate;
      const days = (new Date(iso).getTime() - Date.now()) / 86400000;
      return days <= 7;
    }
    return /(?:hoje|amanhã|\b[0-7]\s*dias?\b)/i.test(product.expiry || '');
  }

  function needsAttention(product) {
    if (product.active === false) return false;
    return isLowStock(product) || product.status === 'critical' || expiresSoon(product);
  }

  function inventoryGroup(product) {
    if (INVENTORY_GROUPS.includes(product.category)) {
      return product.category;
    }
    const reference = `${product.name || ''} ${product.category || ''}`.toLowerCase();
    if (/bebida|gin|vinho|cerveja|whisky|vodka|rum|suco|água|refrigerante/.test(reference)) {
      return 'Bebidas';
    }
    if (/descart|guardanapo|canudo|embalagem|sacola|copo|luva|papel/.test(reference)) {
      return 'Materiais descartáveis';
    }
    if (/latic|queijo|leite|creme|manteiga|iogurte|frios/.test(reference)) {
      return 'Laticínios e frios';
    }
    if (/proteína|frutos do mar|carne|filé|salmão|camarão|polvo|lula|robalo|peixe/.test(reference)) {
      return 'Carnes e pescados';
    }
    return 'Hortifrúti';
  }

  function formatFileSize(bytes) {
    const n = Number(bytes) || 0;
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${(n / 1024).toFixed(1).replace('.', ',')} KB`;
    return `${(n / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`;
  }

  function loadSavedTheme() {
    try {
      return window.localStorage.getItem('sice_theme') === 'dark' ? 'dark' : 'light';
    } catch {
      return 'light';
    }
  }

  function priorityScore(product) {
    return (
      (product.status === 'critical' ? 4 : 0) +
      (expiresSoon(product) ? 3 : 0) +
      (Number(product.quantity) <= Number(product.minimumStock) ? 2 : 0) +
      (product.status === 'low' ? 1 : 0)
    );
  }

  function refreshIcons() {
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
      window.lucide.createIcons();
    }
  }

  // ---------------------------------------------------------------------------
  // Estado global da aplicação
  // ---------------------------------------------------------------------------
  const initialEl = document.getElementById('sice-initial-state');
  const initialData = initialEl ? JSON.parse(initialEl.textContent || '{}') : {};

  const state = {
    user: initialData.user || null,
    products: Array.isArray(initialData.products) ? initialData.products : [],
    movements: Array.isArray(initialData.movements) ? initialData.movements : [],
    receipts: Array.isArray(initialData.receipts) ? initialData.receipts : [],
    orders: Array.isArray(initialData.orders) ? initialData.orders : [],
    team: Array.isArray(initialData.team) ? initialData.team : [],
    supportTickets: Array.isArray(initialData.supportTickets) ? initialData.supportTickets : [],

    theme: loadSavedTheme(),
    active: 'Visão geral',
    screenHistory: [],
    query: '',
    mobile: false,
    profileOpen: false,
    selectedProductId: null,
    movementProduct: '',
    modal: null, // 'product' | 'entry' | 'exit' | null
    modalError: '',
    modalExitReason: '',
    toast: '',
    toastTimer: null,

    // Ajuda e suporte
    supportSuccess: '',
    supportError: '',
    supportFiles: [],

    // Filtros por tela
    invSearch: '',
    invCategory: 'Todos',
    invOrder: 'priority',
    alertFilter: 'expiry',

    // Relatórios (CMV & Projeção)
    cmvInit: false,
    openingStock: 0,
    purchases: 0,
    closingStock: 0,
    revenue: 45000,
    season: 'regular',

    // Etiquetas
    labelProductId: '',
    labelQuantity: '',
    labelUnit: 'UN',
    labelHandledAt: '',
    labelExpiryDate: '',
    labelLot: '',
    labelResponsibleName: '',
    labelQr: '',
    labelPng: '',

    // Leitor QR
    qrActive: false,
    qrStatus: 'Ative a câmera e aponte para uma etiqueta criada pelo SICE.',
    qrManualProduct: '',
    qrSimulating: false,
    qrStream: null,
    qrTimer: null,
    qrDetecting: false,
  };

  function getSelectedProduct() {
    if (!state.selectedProductId) return null;
    return state.products.find((p) => String(p.id) === String(state.selectedProductId)) || null;
  }

  function showToast(message) {
    state.toast = message;
    renderToast();
    if (state.toastTimer) window.clearTimeout(state.toastTimer);
    state.toastTimer = window.setTimeout(() => {
      state.toast = '';
      renderToast();
    }, 3600);
  }

  function renderToast() {
    const existing = document.querySelector('.toast');
    if (!state.toast) {
      if (existing) existing.remove();
      return;
    }
    const html = `<div class="toast"><span>${icon('check', 15)}</span>${esc(state.toast)}</div>`;
    if (existing) {
      existing.outerHTML = html;
    } else {
      const shell = document.querySelector('.app-shell') || document.body;
      shell.insertAdjacentHTML('beforeend', html);
    }
    refreshIcons();
  }

  function applyTheme(theme) {
    const normalized = theme === 'dark' ? 'dark' : 'light';
    state.theme = normalized;
    document.documentElement.dataset.theme = normalized;
    try {
      window.localStorage.setItem('sice_theme', normalized);
    } catch {
      // Storage indisponível
    }
  }

  applyTheme(state.theme);

  function applyAppState(data) {
    if (!data) return;
    if (data.user) state.user = data.user;
    state.products = Array.isArray(data.products) ? data.products : [];
    state.movements = Array.isArray(data.movements) ? data.movements : [];
    state.receipts = Array.isArray(data.receipts) ? data.receipts : [];
    state.orders = Array.isArray(data.orders) ? data.orders : [];
    state.team = Array.isArray(data.team) ? data.team : [];
    state.supportTickets = Array.isArray(data.supportTickets) ? data.supportTickets : state.supportTickets;
    state.cmvInit = false;
    if (state.selectedProductId && !getSelectedProduct()) {
      state.selectedProductId = null;
    }
  }

  async function reloadState() {
    const res = await fetch('api/state.php', {
      cache: 'no-store',
      credentials: 'same-origin',
    });
    if (res.status === 401) {
      window.location.href = 'index.php';
      return;
    }
    const json = await res.json();
    if (json && json.ok && json.data) {
      applyAppState(json.data);
    }
  }

  async function submitAction(actionName, formData) {
    formData.set('action', actionName);
    try {
      const res = await fetch('api/actions.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
      });
      if (res.status === 401) {
        window.location.href = 'index.php';
        return { ok: false, error: 'Sua sessão expirou. Entre novamente.' };
      }
      const json = await res.json();
      return json;
    } catch {
      return { ok: false, error: 'Erro de comunicação com o servidor.' };
    }
  }

  async function handleActionResult(result, { closeDrawer = false, action = '' } = {}) {
    showToast(result.ok ? result.message : result.error || 'Não foi possível concluir a ação.');
    if (result.ok) {
      state.modal = null;
      state.modalError = '';
      state.modalExitReason = '';
      state.movementProduct = '';
      if (closeDrawer) state.selectedProductId = null;
      if (action === 'createProduct') {
        state.invCategory = 'Todos';
        state.invSearch = '';
        state.query = '';
      }
      if (action === 'createSupportTicket') {
        state.supportSuccess =
          result.message ||
          'Sua mensagem foi enviada ao suporte do SICE e será analisada e respondida em até 48 horas.';
        state.supportError = '';
        state.supportFiles = [];
      }
      if (result.data) {
        applyAppState(result.data);
      } else {
        await reloadState();
      }
      render();
    } else {
      const errMsg = result.error || 'Não foi possível concluir a ação.';
      if (state.modal) {
        state.modalError = errMsg;
        const errEl = document.getElementById('modal-error-banner');
        if (errEl) {
          errEl.textContent = errMsg;
          errEl.style.display = 'block';
        }
      }
      if (action === 'createSupportTicket') {
        state.supportError = errMsg;
        state.supportSuccess = '';
        const errEl = document.getElementById('support-error-banner');
        if (errEl) {
          errEl.textContent = errMsg;
          errEl.style.display = 'flex';
        }
      }
    }
  }

  function stopQrScanner() {
    if (state.qrTimer !== null) {
      window.clearInterval(state.qrTimer);
      state.qrTimer = null;
    }
    if (state.qrStream) {
      state.qrStream.getTracks().forEach((track) => track.stop());
      state.qrStream = null;
    }
    const video = document.getElementById('qr-video');
    if (video) video.srcObject = null;
    state.qrActive = false;
  }

  function navigate(screen) {
    if (screen === state.active) return;
    if (state.active === 'Ler QR Code') stopQrScanner();
    state.screenHistory = [...state.screenHistory, state.active].slice(-12);
    state.active = screen;
    state.mobile = false;
    state.profileOpen = false;
    render();
    window.scrollTo({ top: 0, behavior: 'instant' });
  }

  function goBack() {
    if (state.active === 'Ler QR Code') stopQrScanner();
    const previous = state.screenHistory.length
      ? state.screenHistory[state.screenHistory.length - 1]
      : 'Visão geral';
    state.screenHistory = state.screenHistory.slice(0, -1);
    state.active = previous;
    render();
  }

  // ---------------------------------------------------------------------------
  // Componentes HTML reutilizáveis
  // ---------------------------------------------------------------------------
  function logoHtml({ dark = false, compact = false } = {}) {
    return `
      <div class="brand ${dark ? 'brand--dark' : ''} ${compact ? 'brand--compact' : ''}">
        <div class="brand-mark brand-mark--single" aria-label="SICE">
          <b>S</b>
        </div>
        ${!compact ? '<div><strong>SICE</strong><small>sistema inteligente de estoque</small></div>' : ''}
      </div>
    `;
  }

  function logoutButtonHtml(className, iconOnly = false) {
    return `
      <form class="logout-form" action="api/auth.php" method="post">
        <input type="hidden" name="action" value="logout">
        <button class="${esc(className)}" type="submit" aria-label="Sair da conta" title="Sair da conta">
          ${icon('log-out', iconOnly ? 18 : 17)}
          ${!iconOnly ? ' Sair da conta' : ''}
        </button>
      </form>
    `;
  }

  function emptyStateHtml(text) {
    return `
      <div class="empty-state">
        ${icon('boxes', 22)}
        <strong>${esc(text)}</strong>
        <span>Os registros persistentes aparecerão aqui.</span>
      </div>
    `;
  }

  function pageTitleHtml(kicker, title, text, actionHtml = '') {
    return `
      <section class="page-title">
        <div>
          <div class="eyebrow"><span ></span> ${esc(kicker)}</div>
          <h1>${esc(title)}</h1>
          <p>${esc(text)}</p>
        </div>
        ${actionHtml}
      </section>
    `;
  }

  function productRowsHtml(products, emptyText = 'Nenhum insumo cadastrado') {
    if (!products.length) return emptyStateHtml(emptyText);
    return `
      <div class="product-list">
        ${products
          .map((product) => {
            const statusText =
              product.active === false
                ? 'Inativo'
                : product.status === 'ok'
                  ? 'Saudável'
                  : product.status === 'low'
                    ? 'Estoque baixo'
                    : 'Prioridade PVPS';
            const expiryText = product.expiryDate ? formatDate(product.expiryDate) : product.expiry || '—';
            return `
              <button class="product-row" type="button" data-select-product="${esc(product.id)}">
                <span class="product-art" style="--accent: ${esc(product.accent || '#376759')}">
                  ${icon('boxes', 19)}
                </span>
                <span class="product-main">
                  <strong>${esc(product.name)}</strong>
                  <small>${esc(product.code)} · ${esc(inventoryGroup(product))} · ${esc(product.location)}</small>
                </span>
                <span class="product-stock">
                  <small>DISPONÍVEL</small>
                  <strong>${esc(product.quantity)} <em>${esc(product.unit)}</em></strong>
                </span>
                <span class="product-expiry">
                  <small>VALIDADE</small>
                  <strong>${esc(expiryText)}</strong>
                </span>
                <span class="status status--${esc(product.status)}">
                  <i></i>
                  ${esc(statusText)}
                </span>
                ${icon('chevron-right', 18, 'row-arrow')}
              </button>
            `;
          })
          .join('')}
      </div>
    `;
  }

  // ---------------------------------------------------------------------------
  // Tela de Login
  // ---------------------------------------------------------------------------
  function renderLoginScreen() {
    const hasError = new URLSearchParams(window.location.search).get('loginError') === '1';
    const root = document.getElementById('root');
    root.innerHTML = `
      <div class="login-screen">
        <section class="login-story">
          ${logoHtml()}
          <div class="login-copy">
            <span class="login-overline">GESTÃO QUE ALIMENTA DECISÕES</span>
            <h1>Seu estoque, objetivo como deve ser.</h1>
            <p>Controle insumos, reduza perdas e acompanhe o CMV da sua operação em tempo real.</p>
            <div class="login-proof">
              <strong>−18%</strong>
              <span>de desperdício médio nas operações acompanhadas</span>
            </div>
          </div>
          <small>© 2026 SICE · Controle inteligente para hospitalidade</small>
        </section>
        <section class="login-form-wrap">
          <div class="login-form-card">
            ${logoHtml({ dark: true })}
            <div class="login-heading">
              <span>ACESSO RESTRITO</span>
              <h2>Identifique-se para começar</h2>
              <p>Use o login e a senha fornecidos pela gerência.</p>
            </div>
            <form action="api/auth.php" method="post">
              <input type="hidden" name="action" value="login">
              <label class="login-field">
                <span>E-mail</span>
                <div>
                  ${icon('mail', 17)}
                  <input name="email" type="email" placeholder="seu.nome@empresa.com.br" required>
                </div>
              </label>
              <label class="login-field">
                <span>Senha</span>
                <div>
                  ${icon('key-round', 17)}
                  <input name="password" type="password" placeholder="Digite sua senha" required>
                </div>
              </label>
              <div class="login-options">
                <label>
                  <input name="remember" type="checkbox" checked> Manter conectado
                </label>
              </div>
              ${hasError ? '<p class="form-error">E-mail ou senha incorretos.</p>' : ''}
              <button class="btn btn--primary login-submit" type="submit">
                Entrar no SICE ${icon('arrow-right', 18)}
              </button>
            </form>
            <p class="login-support">Credenciais individuais e protegidas por perfil.</p>
          </div>
        </section>
      </div>
    `;
    refreshIcons();
  }

  // ---------------------------------------------------------------------------
  // Telas do Dashboard
  // ---------------------------------------------------------------------------
  function overviewHtml(canManageInventory) {
    const activeProducts = state.products.filter((p) => p.active !== false);
    const attention = activeProducts.filter(needsAttention);
    const lowStock = activeProducts.filter(isLowStock);
    const totalValue = activeProducts.reduce(
      (sum, p) => sum + Number(p.quantity) * Number(p.unitCost || 0),
      0
    );
    const firstName = (state.user.name || '').split(' ')[0];

    return `
      <section class="welcome">
        <div>
          <div class="eyebrow"><span></span> OPERAÇÃO DE HOJE</div>
          <h1>Bom dia, ${esc(firstName)}.</h1>
          <p>
            Seu estoque exige atenção em <strong>${attention.length} itens</strong>. Vamos organizar as
            prioridades?
          </p>
        </div>
        <div class="welcome-actions">
          ${
            canManageInventory
              ? `<button class="btn btn--light" type="button" data-open-modal="entry">
                  ${icon('arrow-down-left', 18)} Registrar entrada
                </button>`
              : ''
          }
          <button class="btn btn--primary" type="button" data-open-modal="exit">
            ${icon('arrow-up-right', 18)} Registrar baixa
          </button>
        </div>
      </section>
      <section class="metrics-grid ${!canManageInventory ? 'metrics-grid--operational' : ''}">
        ${
          canManageInventory
            ? `
          <article class="metric-card metric-card--dark">
            ${icon('boxes', 22)}
            <span>Valor em estoque</span>
            <strong>${esc(money(totalValue))}</strong>
            <small>Atualizado pelos insumos cadastrados</small>
          </article>
        `
            : ''
        }
        <button class="metric-card metric-card--interactive" type="button" data-overview-card="products">
          <div class="metric-head">
            <div class="metric-icon metric-icon--green">
              ${icon('package-check', 21)}
            </div>
            <span class="metric-card-link">Ver registros ${icon('arrow-right', 14)}</span>
          </div>
          <span>Total de insumos</span>
          <strong>${state.products.length}</strong>
          <small>${activeProducts.length} cadastros ativos</small>
        </button>
        <button class="metric-card metric-card--interactive" type="button" data-overview-card="low-stock">
          <div class="metric-head">
            <div class="metric-icon metric-icon--amber">
              ${icon('triangle-alert', 21)}
            </div>
            <span class="metric-card-link">Ver registros ${icon('arrow-right', 14)}</span>
          </div>
          <span>Estoque mínimo</span>
          <strong>${lowStock.length} <em>itens</em></strong>
          <small>abaixo ou no limite mínimo</small>
        </button>
        <button class="metric-card metric-card--interactive" type="button" data-overview-card="movements">
          <div class="metric-head">
            <div class="metric-icon metric-icon--red">
              ${icon('history', 21)}
            </div>
            <span class="metric-card-link">Ver registros ${icon('arrow-right', 14)}</span>
          </div>
          <span>Movimentações</span>
          <strong>${state.movements.length}</strong>
          <small>registros recentes</small>
        </button>
      </section>
      <section class="panel attention-panel">
        <div class="section-heading">
          <div>
            <span class="section-kicker">PRIORIDADE OPERACIONAL</span>
            <h2>Itens que exigem atenção</h2>
          </div>
          <button type="button" data-nav="Estoque">
            Ver estoque ${icon('arrow-right', 16)}
          </button>
        </div>
        ${productRowsHtml(attention.length ? attention : state.products.slice(0, 5))}
      </section>
    `;
  }

  function getFilteredInventoryProducts() {
    const term = `${state.query} ${state.invSearch}`.trim().toLowerCase();
    return state.products
      .filter((product) => {
        const group = inventoryGroup(product);
        const matchCat = state.invCategory === 'Todos' || group === state.invCategory;
        const matchTerm = `${product.name} ${product.code} ${product.location} ${group}`
          .toLowerCase()
          .includes(term);
        return matchCat && matchTerm;
      })
      .sort((a, b) => {
        if (state.invOrder === 'name') return a.name.localeCompare(b.name, 'pt-BR');
        if (state.invOrder === 'stock') return Number(a.quantity) - Number(b.quantity);
        return priorityScore(b) - priorityScore(a) || a.name.localeCompare(b.name, 'pt-BR');
      });
  }

  function inventoryHtml(canManageInventory) {
    const visible = getFilteredInventoryProducts();
    const actionBtn = canManageInventory
      ? `<button class="btn btn--primary" type="button" data-open-modal="product">${icon('plus', 18)} Novo insumo</button>`
      : '';

    return `
      ${pageTitleHtml(
        'CONTROLE DE INSUMOS',
        'Estoque',
        canManageInventory
          ? 'Cadastre e edite livremente os nomes dos insumos.'
          : 'Selecione entre os insumos oferecidos pela gestão.',
        actionBtn
      )}
      <section class="category-overview inventory-categories" aria-label="Categorias do estoque">
        ${INVENTORY_GROUPS.map((group, index) => {
          const count = state.products.filter((p) => inventoryGroup(p) === group).length;
          return `
            <button class="${state.invCategory === group ? 'active' : ''}" type="button" data-inv-cat="${esc(group)}">
              <i class="category-accent category-accent--${index + 1}"></i>
              <span>CATEGORIA</span>
              <strong>${esc(group)}</strong>
              <small>${count} insumos</small>
            </button>
          `;
        }).join('')}
      </section>
      <section class="panel inventory-panel">
        <div class="inventory-toolbar">
          <label class="inventory-search">
            ${icon('search', 17)}
            <input id="inv-search-input" value="${esc(state.invSearch)}" placeholder="Pesquisar por nome, código, local ou categoria">
          </label>
          <div class="inventory-controls">
            <button type="button" class="${state.invCategory === 'Todos' ? 'active' : ''}" data-inv-cat="Todos">
              Todos (${state.products.length})
            </button>
            <select id="inv-order-select">
              <option value="priority" ${state.invOrder === 'priority' ? 'selected' : ''}>Prioridade</option>
              <option value="name" ${state.invOrder === 'name' ? 'selected' : ''}>Nome A–Z</option>
              <option value="stock" ${state.invOrder === 'stock' ? 'selected' : ''}>Menor estoque</option>
            </select>
          </div>
        </div>
        <div id="inventory-rows-wrap">
          ${productRowsHtml(visible)}
        </div>
      </section>
    `;
  }

  function movementsHtml(canManage) {
    const actionsHtml = `
      <div class="movement-page-actions">
        ${
          canManage
            ? `<button class="btn btn--light" type="button" data-open-modal="entry">${icon('arrow-down-left', 18)} Registrar entrada</button>`
            : ''
        }
        <button class="btn btn--primary" type="button" data-open-modal="exit">
          ${icon('arrow-up-right', 18)} Registrar baixa
        </button>
      </div>
    `;

    const exitReasons = ['Consumo', 'Validade', 'Avaria', 'Outros'];

    const rows = state.movements.length
      ? state.movements
          .map((m) => {
            const product = typeof m.product === 'object' && m.product ? m.product : null;
            const responsible =
              typeof m.createdBy === 'object' && m.createdBy ? m.createdBy.name : 'Responsável';
            const areaText = m.operationalArea
              ? AREA_LABELS[m.operationalArea] || m.operationalArea
              : 'Registro identificado';
            const canEditExit =
              m.type === 'exit' &&
              (canManage || String(m.createdBy?.id ?? '') === String(state.user?.id ?? ''));
            let editorHtml = '';

            if (canManage && m.type === 'entry') {
              editorHtml = `
              <details class="record-editor">
                <summary>Editar entrada</summary>
                <form data-action="updateEntry">
                  <input type="hidden" name="id" value="${esc(m.id)}">
                  <div class="form-grid">
                    <label class="form-field">
                      <span>Quantidade</span>
                      <input name="quantity" type="number" min="0.001" step="any" value="${esc(m.quantity)}" required>
                    </label>
                    <label class="form-field">
                      <span>Unidade</span>
                      <select name="unit">
                        ${UNITS.map((u) => `<option value="${u}" ${m.unit === u ? 'selected' : ''}>${u}</option>`).join('')}
                      </select>
                    </label>
                    <label class="form-field">
                      <span>Fornecedor</span>
                      <input name="supplier" value="${esc(m.supplier || '')}">
                    </label>
                    <label class="form-field">
                      <span>Recebido em</span>
                      <input name="receivedDate" type="date" value="${esc((m.receivedDate || '').slice(0, 10))}">
                    </label>
                    <label class="form-field">
                      <span>Custo unitário</span>
                      <input name="unitCost" type="number" min="0" step="any" value="${esc(m.unitCost ?? '')}">
                    </label>
                    <label class="form-field">
                      <span>Validade</span>
                      <input name="expiryDate" type="date" value="${esc((m.expiryDate || '').slice(0, 10))}">
                    </label>
                  </div>
                  <div class="record-actions">
                    <button class="btn btn--primary" type="submit">Salvar ${icon('arrow-right', 16)}</button>
                    <button class="danger-action" type="button"
                      data-confirm-action="deleteMovement"
                      data-confirm-id="${esc(m.id)}"
                      data-confirm-question="Remover esta entrada e recalcular o saldo?">
                      Remover entrada
                    </button>
                  </div>
                </form>
              </details>
            `;
            } else if (canEditExit) {
              const reasonOptions = exitReasons.includes(m.reason)
                ? exitReasons
                : [m.reason, ...exitReasons];
              const isOther = m.reason === 'Outros';
              const isOperational = state.user.role === 'operational';
              editorHtml = `
              <details class="record-editor">
                <summary>Editar baixa</summary>
                <form data-action="updateExit">
                  <input type="hidden" name="id" value="${esc(m.id)}">
                  <div class="form-grid">
                    <label class="form-field">
                      <span>Quantidade</span>
                      <input name="quantity" type="number" min="0.001" step="any" value="${esc(m.quantity)}" required>
                    </label>
                    <label class="form-field">
                      <span>Unidade</span>
                      <select name="unit">
                        ${UNITS.map((u) => `<option value="${u}" ${m.unit === u ? 'selected' : ''}>${u}</option>`).join('')}
                      </select>
                    </label>
                    <label class="form-field">
                      <span>Motivo da baixa</span>
                      <select name="reason" data-exit-edit-reason="${esc(m.id)}" required>
                        ${reasonOptions
                          .map(
                            (r) =>
                              `<option value="${esc(r)}" ${m.reason === r ? 'selected' : ''}>${r === 'Outros' ? 'Outros — especificar' : esc(r)}</option>`
                          )
                          .join('')}
                      </select>
                    </label>
                    <label class="form-field">
                      <span>Área operacional</span>
                      <select name="operationalArea" ${isOperational ? 'required' : ''}>
                        ${
                          !isOperational
                            ? `<option value="" ${!m.operationalArea ? 'selected' : ''}>Gestão de estoque</option>`
                            : ''
                        }
                        <option value="kitchen" ${m.operationalArea === 'kitchen' ? 'selected' : ''}>Cozinha</option>
                        <option value="bar" ${m.operationalArea === 'bar' ? 'selected' : ''}>Bar</option>
                        ${
                          !isOperational
                            ? `<option value="storekeeper" ${m.operationalArea === 'storekeeper' ? 'selected' : ''}>Almoxarife</option>`
                            : ''
                        }
                      </select>
                    </label>
                    <div class="form-field full-width" data-exit-edit-other="${esc(m.id)}" style="display:${isOther ? 'block' : 'none'}">
                      <label class="form-field full-width">
                        <span>Descreva o motivo</span>
                        <textarea name="otherReason" rows="2" ${isOther ? 'required' : ''} placeholder="Explique o motivo da baixa...">${esc(m.otherReason || '')}</textarea>
                      </label>
                    </div>
                  </div>
                  <div class="record-actions">
                    <button class="btn btn--primary" type="submit">Salvar alterações ${icon('arrow-right', 16)}</button>
                    ${
                      canManage
                        ? `<button class="danger-action" type="button"
                            data-confirm-action="deleteMovement"
                            data-confirm-id="${esc(m.id)}"
                            data-confirm-question="Remover esta baixa e devolver a quantidade ao estoque?">
                            Remover baixa
                          </button>`
                        : ''
                    }
                  </div>
                </form>
              </details>
            `;
            }

            return `
              <div class="movement-history-row">
                <span class="movement-history-product">
                  <i class="${esc(m.type)}">${icon('boxes', 17)}</i>
                  <span>
                    <strong>${esc(product?.name || 'Insumo')}</strong>
                    <small>${esc(product?.code || 'Registro preservado')}</small>
                  </span>
                </span>
                <span class="movement-kind ${esc(m.type)}">
                  <span>
                    <strong>${m.type === 'entry' ? 'Entrada' : 'Baixa'}</strong>
                    <small>${esc(m.reason)}${m.otherReason ? ` · ${esc(m.otherReason)}` : ''}</small>
                  </span>
                </span>
                <span class="movement-responsible">
                  <strong>${esc(responsible)}</strong>
                  <small>${esc(areaText)}</small>
                </span>
                <strong class="${esc(m.type)}">
                  ${m.type === 'entry' ? '+' : '−'}${esc(m.quantity)} ${esc(m.unit)}
                </strong>
                <span>${esc(formatLocalDate(m.createdAt))}</span>
                ${editorHtml}
              </div>
            `;
          })
          .join('')
      : emptyStateHtml('Nenhuma movimentação registrada');

    return `
      ${pageTitleHtml(
        'RASTREABILIDADE',
        'Movimentações',
        'Histórico completo de entradas e baixas registradas.',
        actionsHtml
      )}
      <section class="panel movement-history">
        ${rows}
      </section>
    `;
  }

  function alertsHtml() {
    const expiryAlerts = state.products
      .filter(expiresSoon)
      .sort((a, b) => {
        const da = a.expiryDate ? new Date(a.expiryDate).getTime() : Number.MAX_SAFE_INTEGER;
        const db = b.expiryDate ? new Date(b.expiryDate).getTime() : Number.MAX_SAFE_INTEGER;
        return da - db || a.name.localeCompare(b.name, 'pt-BR');
      });
    const stockAlerts = state.products
      .filter(isLowStock)
      .sort((a, b) => Number(a.quantity) - Number(b.quantity) || a.name.localeCompare(b.name, 'pt-BR'));

    const isExpiry = state.alertFilter !== 'stock';
    const alerts = isExpiry ? expiryAlerts : stockAlerts;

    const noticeHistory = (
      isExpiry
        ? [
            ...expiryAlerts.map((product) => ({
              id: `expiry-${product.id}`,
              title: `${product.name} próximo do vencimento`,
              text: `${product.code} · ${product.expiryDate ? formatDate(product.expiryDate) : product.expiry || 'validade curta'}`,
              date: product.updatedAt || product.createdAt || new Date().toISOString(),
              type: 'expiry',
            })),
            ...state.movements
              .filter((m) => m.type === 'exit' && /validade/i.test(m.reason || ''))
              .map((m) => {
                const prod = typeof m.product === 'object' && m.product ? m.product : null;
                const by = typeof m.createdBy === 'object' && m.createdBy ? m.createdBy.name : 'responsável identificado';
                return {
                  id: `movement-${m.id}`,
                  title: `Descarte por validade: ${prod?.name || 'insumo'}`,
                  text: `${m.quantity} ${m.unit} registrados por ${by}`,
                  date: m.createdAt,
                  type: 'expiry',
                };
              }),
          ]
        : stockAlerts.map((product) => ({
            id: `stock-${product.id}`,
            title: `${product.name} atingiu o estoque mínimo`,
            text: `${product.quantity} ${product.unit} disponíveis · mínimo de ${product.minimumStock} ${product.unit}`,
            date: product.updatedAt || product.createdAt || new Date().toISOString(),
            type: 'stock',
          }))
    )
      .sort((a, b) => new Date(b.date).getTime() - new Date(a.date).getTime())
      .slice(0, 20);

    return `
      ${pageTitleHtml(
        'PRIORIDADE OPERACIONAL',
        'Alertas',
        'Filtre insumos próximos do vencimento ou abaixo da quantidade mínima.'
      )}
      <section class="alert-summary">
        <button class="${isExpiry ? 'active' : ''}" type="button" data-alert-filter="expiry" aria-pressed="${isExpiry}">
          <span class="alert-summary-icon expiry" aria-hidden="true">${icon('calendar-clock', 24)}</span>
          <div class="alert-summary-copy">
            <span>Próximos do vencimento</span>
            <strong>${expiryAlerts.length}</strong>
            <small>prioridade PVPS</small>
          </div>
        </button>
        <button class="${!isExpiry ? 'active' : ''}" type="button" data-alert-filter="stock" aria-pressed="${!isExpiry}">
          <span class="alert-summary-icon stock" aria-hidden="true">${icon('triangle-alert', 24)}</span>
          <div class="alert-summary-copy">
            <span>Abaixo do mínimo</span>
            <strong>${stockAlerts.length}</strong>
            <small>reposição recomendada</small>
          </div>
        </button>
      </section>
      <section class="panel alert-list">
        <div class="section-heading">
          <div>
            <span class="section-kicker">FILTRO ATIVO</span>
            <h2>${isExpiry ? 'Somente próximos do vencimento' : 'Somente abaixo do estoque mínimo'}</h2>
            <p>${
              isExpiry
                ? 'Exibindo exclusivamente insumos com validade próxima ou vencida.'
                : 'Exibindo exclusivamente insumos com quantidade igual ou inferior ao mínimo.'
            }</p>
          </div>
          <span class="count-badge">${alerts.length}</span>
        </div>
        ${productRowsHtml(
          alerts,
          isExpiry ? 'Nenhum insumo próximo do vencimento' : 'Nenhum insumo abaixo do estoque mínimo'
        )}
      </section>
      <section class="panel alert-history">
        <div class="section-heading">
          <div>
            <span class="section-kicker">ÚLTIMOS REGISTROS</span>
            <h2>${isExpiry ? 'Histórico de avisos de validade' : 'Histórico de avisos de estoque mínimo'}</h2>
            <p>${
              isExpiry
                ? 'Registros filtrados exclusivamente por vencimento e validade.'
                : 'Registros filtrados exclusivamente por estoque mínimo.'
            }</p>
          </div>
          <span class="count-badge">${noticeHistory.length}</span>
        </div>
        ${
          noticeHistory.length
            ? `
          <div class="alert-history-list">
            ${noticeHistory
              .map(
                (n) => `
              <article>
                <span class="alert-history-icon ${esc(n.type)}" aria-hidden="true">
                  ${icon(n.type === 'expiry' ? 'calendar-clock' : 'triangle-alert', 19)}
                </span>
                <div>
                  <strong>${esc(n.title)}</strong>
                  <small>${esc(n.text)}</small>
                </div>
                <time>${esc(formatLocalDate(n.date))}</time>
              </article>
            `
              )
              .join('')}
          </div>
        `
            : emptyStateHtml(isExpiry ? 'Nenhum aviso de validade registrado' : 'Nenhum aviso de estoque mínimo registrado')
        }
      </section>
    `;
  }

  function purchasesHtml() {
    const activeProducts = state.products.filter((p) => p.active !== false);
    const ordersHtml = state.orders.length
      ? state.orders
          .map((order) => {
            const orderProductId =
              order.product && typeof order.product === 'object' ? order.product.id : order.product || '';
            return `
              <article>
                <div>
                  <strong>${esc(order.title)}</strong>
                  <small>
                    ${esc(order.quantity)} ${esc(order.unit)} · ${esc(order.supplier || 'Fornecedor a definir')} · ${esc(formatDate(order.expectedDate))}
                  </small>
                </div>
                <span class="order-status order-status--${esc(order.status)}">
                  ${esc(ORDER_STATUS_LABELS[order.status] || order.status)}
                </span>
                <details class="record-editor purchase-order-editor">
                  <summary>Editar pedido</summary>
                  <form data-action="updatePurchaseOrder">
                    <input type="hidden" name="id" value="${esc(order.id)}">
                    <div class="form-grid">
                      <label class="form-field">
                        <span>Item</span>
                        <input name="title" value="${esc(order.title)}" required>
                      </label>
                      <label class="form-field">
                        <span>Insumo</span>
                        <select name="product">
                          <option value="">Compra geral</option>
                          ${state.products
                            .map(
                              (p) =>
                                `<option value="${esc(p.id)}" ${String(orderProductId) === String(p.id) ? 'selected' : ''}>${esc(p.name)}</option>`
                            )
                            .join('')}
                        </select>
                      </label>
                      <label class="form-field">
                        <span>Fornecedor</span>
                        <input name="supplier" value="${esc(order.supplier || '')}">
                      </label>
                      <label class="form-field">
                        <span>Data prevista</span>
                        <input name="expectedDate" type="date" value="${esc((order.expectedDate || '').slice(0, 10))}" required>
                      </label>
                      <label class="form-field">
                        <span>Quantidade</span>
                        <input name="quantity" type="number" min="0.01" step="0.01" value="${esc(order.quantity)}" required>
                      </label>
                      <label class="form-field">
                        <span>Unidade</span>
                        <select name="unit">
                          ${UNITS.map((u) => `<option ${order.unit === u ? 'selected' : ''}>${u}</option>`).join('')}
                        </select>
                      </label>
                      <label class="form-field">
                        <span>Custo unitário</span>
                        <input name="unitCost" type="number" min="0" step="0.01" value="${esc(order.unitCost ?? '')}">
                      </label>
                      <label class="form-field">
                        <span>Status</span>
                        <select name="status">
                          ${Object.entries(ORDER_STATUS_LABELS)
                            .map(
                              ([val, lbl]) =>
                                `<option value="${val}" ${order.status === val ? 'selected' : ''}>${lbl}</option>`
                            )
                            .join('')}
                        </select>
                      </label>
                      <label class="form-field full-width">
                        <span>Observações</span>
                        <input name="notes" value="${esc(order.notes || '')}">
                      </label>
                    </div>
                    <button class="btn btn--primary" type="submit">Salvar ${icon('arrow-right', 16)}</button>
                  </form>
                </details>
                <button class="danger-action" type="button"
                  data-confirm-action="deletePurchaseOrder"
                  data-confirm-id="${esc(order.id)}"
                  data-confirm-question="Remover esta compra planejada?">
                  Remover
                </button>
              </article>
            `;
          })
          .join('')
      : emptyStateHtml('Nenhuma compra futura planejada');

    const receiptsHtml = state.receipts.length
      ? state.receipts
          .map(
            (r) => `
          <a class="receipt-row" href="${esc(r.url || '#')}" target="_blank" rel="noreferrer">
            <span>${icon('file-text', 18)}</span>
            <div>
              <strong>${esc(r.title)}</strong>
              <small>${esc(r.supplier || 'Sem fornecedor')} · ${esc(formatDate(r.purchaseDate))}</small>
            </div>
            <b>${r.amount ? esc(money(r.amount)) : 'Abrir'}</b>
          </a>
        `
          )
          .join('')
      : emptyStateHtml('A pasta ainda está vazia');

    return `
      ${pageTitleHtml(
        'COMPRAS E DOCUMENTOS',
        'Compras',
        'Consulte o histórico, guarde comprovantes e planeje compras futuras.'
      )}
      <section class="panel purchase-planner">
        <div class="section-heading compact">
          <div>
            <span class="section-kicker">PRÓXIMAS COMPRAS</span>
            <h2>Planejamento de reposição</h2>
          </div>
          <span class="count-badge">${state.orders.length}</span>
        </div>
        <form data-action="createPurchaseOrder">
          <div class="form-grid">
            <label class="form-field">
              <span>Item da compra</span>
              <input name="title" required placeholder="Ex.: Pescados da semana">
            </label>
            <label class="form-field">
              <span>Insumo relacionado</span>
              <select name="product">
                <option value="">Compra geral</option>
                ${activeProducts.map((p) => `<option value="${esc(p.id)}">${esc(p.name)}</option>`).join('')}
              </select>
            </label>
            <label class="form-field">
              <span>Fornecedor</span>
              <input name="supplier">
            </label>
            <label class="form-field">
              <span>Data prevista</span>
              <input name="expectedDate" type="date" required>
            </label>
            <label class="form-field">
              <span>Quantidade</span>
              <input name="quantity" type="number" min="0.01" step="0.01" required>
            </label>
            <label class="form-field">
              <span>Unidade</span>
              <select name="unit">
                ${UNITS.map((u) => `<option>${u}</option>`).join('')}
              </select>
            </label>
            <label class="form-field">
              <span>Custo unitário previsto</span>
              <input name="unitCost" type="number" min="0" step="0.01">
            </label>
            <label class="form-field">
              <span>Observações</span>
              <input name="notes">
            </label>
          </div>
          <button class="btn btn--primary" type="submit">Salvar ${icon('arrow-right', 16)}</button>
        </form>
        <div class="purchase-order-list">${ordersHtml}</div>
      </section>
      <section class="purchase-layout">
        <article class="panel upload-card">
          <div class="settings-card-head">
            <span>${icon('upload', 20)}</span>
            <div>
              <h2>Novo comprovante</h2>
              <p>Use a câmera do celular ou escolha um PDF/imagem.</p>
            </div>
          </div>
          <form data-action="uploadReceipt">
            <div class="form-grid">
              <label class="form-field">
                <span>Título</span>
                <input name="title" required placeholder="Ex.: Nota fiscal hortifruti">
              </label>
              <label class="form-field">
                <span>Fornecedor</span>
                <input name="supplier" placeholder="Nome do fornecedor">
              </label>
              <label class="form-field">
                <span>Data da compra</span>
                <input name="purchaseDate" type="date" required>
              </label>
              <label class="form-field">
                <span>Valor</span>
                <input name="amount" type="number" min="0" step="0.01" placeholder="0,00">
              </label>
            </div>
            <label class="receipt-drop">
              ${icon('paperclip', 24)}
              <strong>Escanear ou fazer upload</strong>
              <span>PDF, JPG, PNG ou WEBP · até 10 MB</span>
              <input name="file" type="file" accept="application/pdf,image/*" capture="environment" required>
            </label>
            <button class="btn btn--primary" type="submit">Salvar ${icon('arrow-right', 16)}</button>
          </form>
        </article>
        <article class="panel receipt-folder">
          <div class="section-heading compact">
            <div>
              <span class="section-kicker">PASTA DE COMPROVANTES</span>
              <h2>Arquivos guardados</h2>
            </div>
            <span class="count-badge">${state.receipts.length}</span>
          </div>
          ${receiptsHtml}
        </article>
      </section>
    `;
  }

  function computeReportContext() {
    const exits = state.movements.filter((m) => m.type === 'exit');
    const stockValue = state.products.reduce(
      (sum, p) => sum + Number(p.quantity) * Number(p.unitCost || 0),
      0
    );
    const now = new Date();
    const isCurrentMonth = (val) => {
      const d = new Date(val);
      return d.getMonth() === now.getMonth() && d.getFullYear() === now.getFullYear();
    };
    const monthlyMovs = state.movements.filter((m) => isCurrentMonth(m.receivedDate || m.createdAt));
    const movVal = (m) => {
      const p = typeof m.product === 'object' && m.product ? m.product : null;
      return Number(m.quantity) * Number(m.unitCost || p?.unitCost || 0);
    };
    const monthlyEntriesValue = monthlyMovs
      .filter((m) => m.type === 'entry')
      .reduce((s, m) => s + movVal(m), 0);
    const monthlyExitsValue = monthlyMovs
      .filter((m) => m.type === 'exit')
      .reduce((s, m) => s + movVal(m), 0);
    const monthlyReceiptTotal = state.receipts
      .filter((r) => isCurrentMonth(r.purchaseDate))
      .reduce((s, r) => s + Number(r.amount || 0), 0);

    const lossMovements = exits.filter((m) => /descarte|avaria|validade|outros/i.test(m.reason || ''));
    const lossCost = lossMovements.reduce((s, m) => {
      const p = typeof m.product === 'object' && m.product ? m.product : null;
      return s + Number(m.quantity) * Number(p?.unitCost || 0);
    }, 0);
    const averageWaste = lossMovements.length ? lossCost / lossMovements.length : 0;

    if (!state.cmvInit) {
      const calcPurchases = monthlyEntriesValue || monthlyReceiptTotal;
      const calcOpening = Math.max(0, stockValue - monthlyEntriesValue + monthlyExitsValue);
      const suggestedRevenue = Math.max(45000, (calcOpening + calcPurchases - stockValue) / 0.29);
      const nextMonth = new Date(now.getFullYear(), now.getMonth() + 1, 1);
      state.openingStock = Math.round(calcOpening);
      state.purchases = Math.round(calcPurchases);
      state.closingStock = Math.round(stockValue);
      state.revenue = Math.round(suggestedRevenue);
      state.season = [11, 0, 1].includes(nextMonth.getMonth()) ? 'high' : 'regular';
      state.cmvInit = true;
    }

    const nextMonth = new Date(now.getFullYear(), now.getMonth() + 1, 1);
    const nextMonthName = nextMonth.toLocaleDateString('pt-BR', { month: 'long' });
    const selectedSeason = SEASONALITY[state.season] || SEASONALITY.regular;
    const cmv = Math.max(0, state.openingStock + state.purchases - state.closingStock);
    const cmvRate = state.revenue > 0 ? (cmv / state.revenue) * 100 : 0;
    const monthlyConsumptionBase = Math.max(monthlyExitsValue, cmv * 0.72);
    const projectedConsumption = monthlyConsumptionBase * selectedSeason.factor;
    const projectedPurchases = Math.max(0, projectedConsumption + state.closingStock * 0.2);

    return {
      exits,
      stockValue,
      lossMovements,
      averageWaste,
      nextMonthName,
      selectedSeason,
      cmv,
      cmvRate,
      projectedConsumption,
      projectedPurchases,
    };
  }

  function updateReportDynamicNumbers() {
    const ctx = computeReportContext();
    const cmvEl = document.getElementById('cmv-result-value');
    const rateEl = document.getElementById('cmv-rate-value');
    const noteEl = document.getElementById('season-note');
    const consEl = document.getElementById('proj-consumption');
    const purchEl = document.getElementById('proj-purchases');
    if (cmvEl) cmvEl.textContent = money(ctx.cmv);
    if (rateEl) rateEl.textContent = `${ctx.cmvRate.toFixed(1).replace('.', ',')}%`;
    if (noteEl) noteEl.textContent = ctx.selectedSeason.note;
    if (consEl) consEl.textContent = money(ctx.projectedConsumption);
    if (purchEl) purchEl.textContent = money(ctx.projectedPurchases);
  }

  function reportsHtml(isOwner) {
    const ctx = computeReportContext();
    const lowCount = state.products.filter(isLowStock).length;

    // Consumo mensal dinâmico (últimos 8 meses: Alimentos vs Bebidas)
    const now = new Date();
    const monthBuckets = [];
    for (let offset = 7; offset >= 0; offset -= 1) {
      const dt = new Date(now.getFullYear(), now.getMonth() - offset, 1);
      const key = `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}`;
      const labelRaw = dt.toLocaleDateString('pt-BR', { month: 'short' }).replace('.', '');
      const label = labelRaw.charAt(0).toUpperCase() + labelRaw.slice(1);
      monthBuckets.push({ key, label, food: 0, beverage: 0 });
    }
    const bucketMap = Object.fromEntries(monthBuckets.map((b) => [b.key, b]));
    ctx.exits.forEach((m) => {
      const dt = new Date(m.createdAt);
      if (Number.isNaN(dt.getTime())) return;
      const key = `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}`;
      const bucket = bucketMap[key];
      if (!bucket) return;
      const prod = typeof m.product === 'object' && m.product ? m.product : null;
      const cost = Number(m.quantity) * Number(m.unitCost || prod?.unitCost || 1);
      if (prod && inventoryGroup(prod) === 'Bebidas') {
        bucket.beverage += cost;
      } else {
        bucket.food += cost;
      }
    });
    const maxMonthly = Math.max(
      1,
      ...monthBuckets.map((b) => Math.max(b.food, b.beverage))
    );

    // Motivos de descarte dinâmicos a partir das baixas reais
    const reasonDefs = [
      { key: 'Consumo', color: '#376759', count: 0 },
      { key: 'Avaria', color: '#b9995b', count: 0 },
      { key: 'Validade', color: '#a54e44', count: 0 },
      { key: 'Outros', color: '#94a09a', count: 0 },
    ];
    ctx.exits.forEach((m) => {
      const r = (m.reason || '').toLowerCase();
      if (r.includes('consumo')) reasonDefs[0].count += 1;
      else if (r.includes('avaria')) reasonDefs[1].count += 1;
      else if (r.includes('validade')) reasonDefs[2].count += 1;
      else reasonDefs[3].count += 1;
    });
    const totalExits = ctx.exits.length;
    let accPct = 0;
    const gradientStops = [];
    const reasonRows = reasonDefs.map((item) => {
      const pct = totalExits > 0 ? Math.round((item.count / totalExits) * 100) : 0;
      if (totalExits > 0 && item.count > 0) {
        const start = accPct;
        accPct += (item.count / totalExits) * 100;
        gradientStops.push(`${item.color} ${start.toFixed(1)}% ${accPct.toFixed(1)}%`);
      }
      return { ...item, pct };
    });
    const donutRing = gradientStops.length
      ? `conic-gradient(${gradientStops.join(', ')})`
      : 'conic-gradient(#d4dad6 0% 100%)';

    return `
      ${pageTitleHtml(
        'INTELIGÊNCIA OPERACIONAL',
        isOwner ? 'Relatórios & CMV' : 'Relatórios',
        'Indicadores de estoque, consumo e perdas disponíveis à gestão.'
      )}
      ${
        isOwner
          ? `
        <section class="owner-intelligence">
          <article class="panel cmv-card">
            <div class="report-card-head">
              <div>
                <span class="section-kicker">CUSTO DA MERCADORIA VENDIDA</span>
                <h2>Calculadora de CMV</h2>
              </div>
              <span class="report-icon">${icon('calculator', 20)}</span>
            </div>
            <div class="cmv-formula">
              <label>
                <span>Estoque inicial do mês</span>
                <input type="number" min="0" data-cmv="openingStock" value="${esc(state.openingStock)}">
              </label>
              <i>+</i>
              <label>
                <span>Compras durante o mês</span>
                <input type="number" min="0" data-cmv="purchases" value="${esc(state.purchases)}">
              </label>
              <i>−</i>
              <label>
                <span>Estoque final do mês</span>
                <input type="number" min="0" data-cmv="closingStock" value="${esc(state.closingStock)}">
              </label>
              <i>=</i>
              <div class="cmv-result">
                <span>CMV calculado</span>
                <strong id="cmv-result-value">${esc(money(ctx.cmv))}</strong>
              </div>
            </div>
            <div class="cmv-rate">
              <label>
                <span>Faturamento do período</span>
                <input type="number" min="0" data-cmv="revenue" value="${esc(state.revenue)}">
              </label>
              <div>
                <span>CMV sobre faturamento</span>
                <strong id="cmv-rate-value">${esc(ctx.cmvRate.toFixed(1).replace('.', ','))}%</strong>
                <small>Referência saudável sugerida: 28% a 35%</small>
              </div>
            </div>
            <p class="cmv-source-note">
              Resumo do mês atual: estoque inicial é reconstruído pelo saldo e movimentos; compras
              usam entradas com custo ou, na ausência delas, os comprovantes do mês; estoque final
              usa o saldo atual. Ajuste apenas para conciliação contábil.
            </p>
          </article>
          <article class="panel projection-card">
            <div class="report-card-head">
              <div>
                <span class="section-kicker">PROJEÇÃO SAZONAL</span>
                <h2>Próximo mês</h2>
              </div>
              <span class="report-icon">${icon('trending-up', 20)}</span>
            </div>
            <div class="projection-content">
              <span class="projection-month">${esc(ctx.nextMonthName)}</span>
              <label class="form-field">
                <span>Cenário esperado</span>
                <select id="season-select">
                  ${Object.entries(SEASONALITY)
                    .map(
                      ([val, opt]) =>
                        `<option value="${val}" ${state.season === val ? 'selected' : ''}>${esc(opt.label)}</option>`
                    )
                    .join('')}
                </select>
              </label>
              <p id="season-note">${esc(ctx.selectedSeason.note)}</p>
              <div class="projection-values">
                <span>
                  <small>Consumo projetado</small>
                  <strong id="proj-consumption">${esc(money(ctx.projectedConsumption))}</strong>
                </span>
                <span>
                  <small>Sugestão de compras</small>
                  <strong id="proj-purchases">${esc(money(ctx.projectedPurchases))}</strong>
                </span>
              </div>
              <small class="projection-disclaimer">
                Simulação baseada no CMV, nas baixas recentes e em um fator sazonal ajustável.
              </small>
            </div>
          </article>
        </section>
      `
          : ''
      }
      <section class="report-metrics">
        <article>
          <span>Valor em estoque</span>
          <strong>${esc(money(ctx.stockValue))}</strong>
          <small class="positive">Saldo atual</small>
        </article>
        <article>
          <span>Baixas registradas</span>
          <strong>${ctx.exits.length}</strong>
          <small>Histórico recente</small>
        </article>
        <article>
          <span>Perdas e descartes</span>
          <strong>${ctx.lossMovements.length}</strong>
          <small class="positive">Média por ocorrência: ${esc(money(ctx.averageWaste))}</small>
        </article>
        <article>
          <span>Itens abaixo do mínimo</span>
          <strong>${lowCount}</strong>
          <small>Ação recomendada</small>
        </article>
      </section>
      <section class="reports-grid">
        <article class="panel consumption-chart">
          <div class="report-card-head">
            <div>
              <span class="section-kicker">EVOLUÇÃO MENSAL</span>
              <h2>Consumo por categoria</h2>
            </div>
            <div class="chart-legend">
              <span><i></i> Alimentos</span>
              <span><i></i> Bebidas</span>
            </div>
          </div>
          <div class="bar-chart" aria-label="Gráfico de consumo mensal">
            ${monthBuckets
              .map((b) => {
                const foodH = b.food > 0 ? Math.max(12, Math.round((b.food / maxMonthly) * 100)) : 4;
                const bevH = b.beverage > 0 ? Math.max(12, Math.round((b.beverage / maxMonthly) * 100)) : 4;
                return `
              <div title="${esc(b.label)} · Alimentos: ${esc(money(b.food))} · Bebidas: ${esc(money(b.beverage))}">
                <span style="height: ${foodH}%"></span>
                <i style="height: ${bevH}%"></i>
                <small>${esc(b.label)}</small>
              </div>
            `;
              })
              .join('')}
          </div>
        </article>
        <article class="panel loss-card">
          <div class="report-card-head">
            <div>
              <span class="section-kicker">PERDAS</span>
              <h2>Motivos de descarte</h2>
            </div>
          </div>
          <div class="donut-wrap">
            <div class="donut" style="--donut-ring: ${donutRing}">
              <strong>${ctx.exits.length}</strong>
              <span>baixas</span>
            </div>
            <div class="donut-list">
              ${reasonRows
                .map(
                  (item) => `
                <div>
                  <i style="background: ${item.color}"></i>
                  <span>${esc(item.key)} (${item.count})</span>
                  <strong>${item.pct}%</strong>
                </div>
              `
                )
                .join('')}
            </div>
          </div>
        </article>
        <article class="panel responsible-report">
          <div class="report-card-head">
            <div>
              <span class="section-kicker">RASTREABILIDADE</span>
              <h2>Quem registrou as baixas</h2>
            </div>
          </div>
          ${
            ctx.exits.length
              ? ctx.exits
                  .slice(0, 8)
                  .map((m) => {
                    const prod = typeof m.product === 'object' && m.product ? m.product : null;
                    const by = typeof m.createdBy === 'object' && m.createdBy ? m.createdBy.name : 'Responsável';
                    const area = m.operationalArea ? AREA_LABELS[m.operationalArea] : 'Gestão de estoque';
                    return `
                      <div class="responsible-row">
                        <span>
                          <strong>${esc(by)}</strong>
                          <small>${esc(area)}</small>
                        </span>
                        <span>
                          <strong>${esc(prod?.name || 'Insumo')}</strong>
                          <small>${esc(m.reason)}</small>
                        </span>
                        <b>−${esc(m.quantity)} ${esc(m.unit)}</b>
                      </div>
                    `;
                  })
                  .join('')
              : emptyStateHtml('Nenhuma baixa registrada')
          }
        </article>
      </section>
    `;
  }

  function teamHtml() {
    return `
      ${pageTitleHtml(
        'GESTÃO DE ACESSOS',
        'Equipe',
        'Crie e gerencie login, senha, função e disponibilidade de cada funcionário.'
      )}
      <section class="team-layout">
        <article class="panel credentials-card">
          <div class="settings-card-head">
            <span>${icon('users-round', 20)}</span>
            <div>
              <h2>Novo funcionário</h2>
              <p>O acesso será individual e respeitará o perfil escolhido.</p>
            </div>
          </div>
          <form data-action="createEmployee" autocomplete="off">
            <datalist id="sice-function-options">
              <option value="Cozinheiro"></option>
              <option value="Bartender"></option>
              <option value="Almoxarife"></option>
            </datalist>
            <div class="form-grid">
              <label class="form-field">
                <span>Nome</span>
                <input name="name" required>
              </label>
              <label class="form-field">
                <span>Função</span>
                <input name="function" list="sice-function-options" required placeholder="Ex.: Cozinheiro, bartender...">
              </label>
              <label class="form-field">
                <span>E-mail de login</span>
                <input name="email" type="email" autocomplete="off" required>
              </label>
              <label class="form-field">
                <span>Senha inicial</span>
                <input name="password" type="password" minlength="4" autocomplete="new-password" required>
              </label>
              <label class="form-field full-width">
                <span>Perfil de acesso</span>
                <select name="role">
                  <option value="operational">Equipe operacional</option>
                  <option value="storekeeper">Almoxarife</option>
                </select>
              </label>
            </div>
            <button class="btn btn--primary" type="submit">Salvar ${icon('arrow-right', 16)}</button>
          </form>
        </article>
        <article class="panel team-list">
          <div class="section-heading compact">
            <div>
              <span class="section-kicker">ACESSOS ATIVOS</span>
              <h2>Funcionários</h2>
            </div>
            <span class="count-badge">${state.team.length}</span>
          </div>
          ${state.team
            .map(
              (member) => `
            <article class="team-row ${member.active === false ? 'is-inactive' : ''}">
              <i>${esc((member.name || '').slice(0, 2).toUpperCase())}</i>
              <div>
                <strong>${esc(member.name)}</strong>
                <small>${esc(member.function || ROLE_LABELS[member.role] || member.role)} · ${esc(member.email)}</small>
              </div>
              <span>${member.active === false ? 'Inativo' : esc(ROLE_LABELS[member.role] || member.role)}</span>
              ${
                member.role !== 'owner'
                  ? `
                <details class="record-editor">
                  <summary>Gerenciar acesso</summary>
                  <form data-action="updateEmployee" autocomplete="off">
                    <input type="hidden" name="id" value="${esc(member.id)}">
                    <div class="form-grid">
                      <label class="form-field">
                        <span>Nome</span>
                        <input name="name" value="${esc(member.name)}" required>
                      </label>
                      <label class="form-field">
                        <span>Função</span>
                        <input name="function" value="${esc(member.function || '')}" list="sice-function-options" placeholder="Ex.: Cozinheiro, bartender...">
                      </label>
                      <label class="form-field">
                        <span>E-mail</span>
                        <input name="email" type="email" value="${esc(member.email)}" autocomplete="off" required>
                      </label>
                      <label class="form-field">
                        <span>Nova senha (opcional)</span>
                        <input name="password" type="password" minlength="4" autocomplete="new-password" placeholder="Deixe em branco para manter">
                      </label>
                      <label class="form-field">
                        <span>Perfil</span>
                        <select name="role">
                          <option value="operational" ${member.role === 'operational' ? 'selected' : ''}>Equipe operacional</option>
                          <option value="storekeeper" ${member.role === 'storekeeper' ? 'selected' : ''}>Almoxarife</option>
                        </select>
                      </label>
                      <label class="form-field">
                        <span>Situação</span>
                        <select name="active">
                          <option value="true" ${member.active !== false ? 'selected' : ''}>Ativo</option>
                          <option value="false" ${member.active === false ? 'selected' : ''}>Inativo</option>
                        </select>
                      </label>
                    </div>
                    <button class="btn btn--primary" type="submit">Salvar ${icon('arrow-right', 16)}</button>
                  </form>
                </details>
              `
                  : ''
              }
            </article>
          `
            )
            .join('')}
        </article>
      </section>
    `;
  }

  function labelsHtml() {
    const activeProducts = state.products.filter((p) => p.active !== false);
    if (!state.labelProductId && activeProducts[0]) {
      state.labelProductId = String(activeProducts[0].id);
    }
    const product = activeProducts.find((p) => String(p.id) === String(state.labelProductId));
    const displayQuantity = state.labelQuantity
      ? `${new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 3 }).format(Number(state.labelQuantity))} ${state.labelUnit}`
      : '—';

    return `
      ${pageTitleHtml(
        'IDENTIFICAÇÃO',
        'Criar etiquetas',
        'Selecione o insumo e registre quantidade, datas, lote e responsável pela manipulação.'
      )}
      <section class="qr-layout labels-layout">
        <article class="panel qr-builder">
          <div class="settings-card-head">
            <span>${icon('qr-code', 20)}</span>
            <div>
              <h2>Configurar etiqueta</h2>
              <p>Os dados serão salvos e incorporados ao QR Code.</p>
            </div>
          </div>
          <form data-action="createLabel" class="qr-form" id="label-form">
            <label class="form-field full-width">
              <span>1. Insumo</span>
              <select name="product" data-label-field="labelProductId" required>
                <option value="">Selecione um insumo</option>
                ${activeProducts
                  .map(
                    (p) =>
                      `<option value="${esc(p.id)}" ${String(state.labelProductId) === String(p.id) ? 'selected' : ''}>${esc(p.name)} · ${esc(p.code)}</option>`
                  )
                  .join('')}
              </select>
            </label>
            <label class="form-field">
              <span>2. Quantidade</span>
              <input name="quantity" type="number" min="0.001" step="0.001" data-label-field="labelQuantity" value="${esc(state.labelQuantity)}" required placeholder="Ex.: 0,300">
            </label>
            <label class="form-field">
              <span>3. Unidade de medida</span>
              <select name="unit" data-label-field="labelUnit" required>
                ${LABEL_UNITS.map((u) => `<option value="${u}" ${state.labelUnit === u ? 'selected' : ''}>${u}</option>`).join('')}
              </select>
            </label>
            <label class="form-field">
              <span>4. Data de manipulação</span>
              <input name="handledAt" type="date" data-label-field="labelHandledAt" value="${esc(state.labelHandledAt)}" required>
            </label>
            <label class="form-field">
              <span>5. Data de validade</span>
              <input name="expiryDate" type="date" ${state.labelHandledAt ? `min="${esc(state.labelHandledAt)}"` : ''} data-label-field="labelExpiryDate" value="${esc(state.labelExpiryDate)}" required>
            </label>
            <label class="form-field">
              <span>6. Lote</span>
              <input name="lot" data-label-field="labelLot" value="${esc(state.labelLot)}" required placeholder="Ex.: L-1105">
            </label>
            <label class="form-field">
              <span>7. Responsável</span>
              <input name="responsibleName" data-label-field="labelResponsibleName" value="${esc(state.labelResponsibleName)}" required placeholder="Digite o nome">
            </label>
            <div class="label-form-actions full-width">
              <button class="btn btn--light" type="button" id="btn-generate-label">
                ${icon('qr-code', 17)} Gerar prévia
              </button>
              <button class="btn btn--primary" type="submit">
                ${icon('check', 17)} Salvar etiqueta
              </button>
            </div>
          </form>
        </article>
        <aside class="panel qr-preview-card" id="label-preview-card">
          ${labelPreviewInnerHtml(product, displayQuantity)}
        </aside>
      </section>
    `;
  }

  function estimateLabelTitleFontSize(name) {
    const len = String(name || '').trim().length;
    if (len <= 16) return 34;
    if (len <= 24) return 28;
    if (len <= 34) return 24;
    if (len <= 48) return 20;
    if (len <= 68) return 17;
    return 14;
  }

  function fitLabelTitle() {
    const label = document.querySelector('.print-label');
    const info = label ? label.querySelector('.label-information') : null;
    const title = info ? info.querySelector('.label-product-title') : null;
    if (!label || !info || !title) return;

    const labelWidth = label.clientWidth || 560;
    const labelHeight = label.clientHeight || Math.round(labelWidth / 1.5);
    const maxFont = Math.min(38, Math.max(18, Math.round(labelWidth * 0.068)));
    const minFont = 10;
    const maxTitleHeight = Math.max(36, Math.round(labelHeight * 0.28));

    let size = Math.min(maxFont, estimateLabelTitleFontSize(title.textContent) + 6);
    title.style.fontSize = `${size}px`;

    // Ajusta dinamicamente até caber sem corte horizontal nem sobreposição vertical
    const fits = () => {
      const noHorizontalOverflow = title.scrollWidth <= title.clientWidth + 1;
      const titleHeightOk = title.scrollHeight <= maxTitleHeight + 1;
      const infoFitsInsideLabel = info.scrollHeight <= info.clientHeight + 2;
      return noHorizontalOverflow && titleHeightOk && infoFitsInsideLabel;
    };

    // Tenta crescer até o máximo se couber com folga (para nomes curtos)
    while (size < maxFont) {
      const next = size + 1;
      title.style.fontSize = `${next}px`;
      if (!fits()) {
        title.style.fontSize = `${size}px`;
        break;
      }
      size = next;
    }

    // Reduz progressivamente até caber integralmente sem cortes ou sobreposições
    while (size > minFont && !fits()) {
      size -= 1;
      title.style.fontSize = `${size}px`;
    }
  }

  function wrapCanvasText(ctx, text, maxWidth) {
    const cleaned = String(text || '').trim().replace(/\s+/g, ' ');
    if (!cleaned) return [''];
    const words = cleaned.split(' ');
    const lines = [];
    let current = '';

    const pushLongWord = (word) => {
      let chunk = '';
      for (const ch of word) {
        if (ctx.measureText(chunk + ch).width > maxWidth && chunk) {
          lines.push(chunk);
          chunk = ch;
        } else {
          chunk += ch;
        }
      }
      if (chunk) current = chunk;
    };

    for (const word of words) {
      const test = current ? `${current} ${word}` : word;
      if (ctx.measureText(test).width <= maxWidth) {
        current = test;
      } else if (!current) {
        pushLongWord(word);
      } else {
        lines.push(current);
        current = '';
        if (ctx.measureText(word).width <= maxWidth) {
          current = word;
        } else {
          pushLongWord(word);
        }
      }
    }
    if (current) lines.push(current);
    return lines;
  }

  function drawFittedCanvasTitle(ctx, text, x, topY, maxWidth, maxHeight) {
    let fontSize = 64;
    const minFontSize = 22;
    let lines = [text];
    let lineHeight = fontSize * 1.08;

    while (fontSize >= minFontSize) {
      ctx.font = `700 ${fontSize}px sans-serif`;
      lines = wrapCanvasText(ctx, text, maxWidth);
      lineHeight = Math.round(fontSize * 1.1);
      const totalHeight = lines.length * lineHeight;
      const allLinesFitWidth = lines.every((line) => ctx.measureText(line).width <= maxWidth + 1);
      if (allLinesFitWidth && totalHeight <= maxHeight) {
        break;
      }
      fontSize -= 2;
    }

    ctx.font = `700 ${Math.max(fontSize, minFontSize)}px sans-serif`;
    const totalHeight = lines.length * lineHeight;
    const startY = topY + Math.max(0, Math.round((maxHeight - totalHeight) / 2)) + Math.round(fontSize * 0.82);

    lines.forEach((line, idx) => {
      ctx.fillText(line, x, startY + idx * lineHeight);
    });
  }

  function labelPreviewInnerHtml(product, displayQuantity) {
    const productName = product?.name || 'Nome do insumo';
    const initialTitleSize = estimateLabelTitleFontSize(productName);
    return `
      <div class="preview-title">
        <span>PRÉVIA DE IMPRESSÃO</span>
        <small>Etiqueta 90 × 60 mm</small>
      </div>
      <div class="print-label">
        <div class="label-qr-column">
          ${
            state.labelQr
              ? `<img src="${esc(state.labelQr)}" alt="QR Code da etiqueta de ${esc(product?.name || 'insumo')}" width="420" height="420">`
              : `<div class="qr-placeholder">${icon('qr-code', 62)}<span>Preencha os campos e gere a prévia</span></div>`
          }
          <strong>${esc(product?.code || 'CÓDIGO')}</strong>
        </div>
        <div class="label-information">
          <h3 class="label-product-title" style="font-size: ${initialTitleSize}px" title="${esc(productName)}">${esc(productName)}</h3>
          <p>Quantidade: <strong>${esc(displayQuantity)}</strong></p>
          <p>Manip.: <strong>${esc(formatDate(state.labelHandledAt))}</strong></p>
          <p class="label-expiry">VAL.: <strong>${esc(formatDate(state.labelExpiryDate))}</strong></p>
          <p>Lote: <strong>${esc(state.labelLot || '—')}</strong></p>
          <p class="label-responsible">Resp.: <strong>${esc(state.labelResponsibleName || '—')}</strong></p>
          <footer>
            <b>SICE</b>
            <span>Allto Al Mar</span>
          </footer>
        </div>
      </div>
      <div class="qr-actions">
        ${
          state.labelPng
            ? `<a class="btn btn--primary" href="${esc(state.labelPng)}" download="etiqueta-${esc(product?.name || 'insumo')}-${esc(state.labelLot || 'lote')}.png">${icon('download', 17)} Baixar PNG</a>`
            : `<button class="btn btn--primary" disabled type="button">${icon('download', 17)} Baixar PNG</button>`
        }
        <button class="btn btn--light" type="button" id="btn-print-label">Imprimir</button>
      </div>
      <p>${icon('check', 15)} QR Code e dados legíveis em uma etiqueta pronta para impressão.</p>
    `;
  }

  function loadScriptOnce(src) {
    return new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = src;
      script.async = true;
      script.onload = () => resolve();
      script.onerror = () => reject(new Error(`Falha ao carregar ${src}`));
      document.head.appendChild(script);
    });
  }

  async function ensureQrCodeLibrary() {
    if (window.QRCode && typeof window.QRCode.toDataURL === 'function') {
      return window.QRCode;
    }
    const fallbacks = [
      'https://cdnjs.cloudflare.com/ajax/libs/qrcode/1.5.1/qrcode.min.js',
      'https://unpkg.com/qrcode@1.5.1/build/qrcode.min.js',
      'https://cdn.jsdelivr.net/npm/qrcode@1.5.1/build/qrcode.min.js',
    ];
    for (const url of fallbacks) {
      try {
        await loadScriptOnce(url);
        if (window.QRCode && typeof window.QRCode.toDataURL === 'function') {
          return window.QRCode;
        }
      } catch {
        // Tenta o próximo CDN
      }
    }
    return null;
  }

  function loadQrImage(src) {
    return new Promise((resolve, reject) => {
      const img = new window.Image();
      img.onload = () => resolve(img);
      img.onerror = () => reject(new Error('Falha ao renderizar imagem do QR Code.'));
      img.src = src;
      if (typeof img.decode === 'function') {
        img.decode().then(() => resolve(img)).catch(() => {
          // Aguarda onload/onerror padrão caso decode() falhe
        });
      }
    });
  }

  async function generateLabelPreview() {
    const activeProducts = state.products.filter((p) => p.active !== false);
    const product = activeProducts.find((p) => String(p.id) === String(state.labelProductId));
    if (
      !product ||
      !state.labelQuantity ||
      Number(state.labelQuantity) <= 0 ||
      !state.labelHandledAt ||
      !state.labelExpiryDate ||
      !state.labelLot.trim() ||
      !state.labelResponsibleName.trim()
    ) {
      showToast('Preencha todos os campos para gerar a prévia.');
      return;
    }
    if (state.labelExpiryDate < state.labelHandledAt) {
      showToast('A validade não pode ser anterior à manipulação.');
      return;
    }

    const qrLib = await ensureQrCodeLibrary();
    if (!qrLib) {
      showToast('Biblioteca QRCode não carregada. Verifique a conexão com a internet.');
      return;
    }

    const payload = {
      productId: product.id,
      productCode: product.code,
      productName: product.name,
      quantity: Number(state.labelQuantity),
      unit: state.labelUnit,
      handledAt: state.labelHandledAt,
      expiryDate: state.labelExpiryDate,
      lot: state.labelLot.trim(),
      responsibleName: state.labelResponsibleName.trim(),
    };

    try {
      const qrData = await qrLib.toDataURL(JSON.stringify(payload), {
        errorCorrectionLevel: 'H',
        width: 420,
        margin: 1,
      });
      state.labelQr = qrData;

      const displayQuantity = `${new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 3 }).format(Number(state.labelQuantity))} ${state.labelUnit}`;
      const canvas = document.createElement('canvas');
      canvas.width = 1350;
      canvas.height = 900;
      const ctx = canvas.getContext('2d');
      if (ctx) {
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        const qrImage = await loadQrImage(qrData);
        ctx.imageSmoothingEnabled = false;
        ctx.drawImage(qrImage, 55, 95, 500, 500);
        ctx.fillStyle = '#111111';
        ctx.textAlign = 'center';
        ctx.font = '700 48px sans-serif';
        ctx.fillText(product.code, 305, 670);

        ctx.textAlign = 'left';
        ctx.fillStyle = '#111111';
        drawFittedCanvasTitle(ctx, product.name, 635, 58, 650, 128);
        ctx.font = '400 48px sans-serif';
        ctx.fillText(`Quantidade: ${displayQuantity}`, 635, 240);
        ctx.fillText(`Manip.: ${formatDate(state.labelHandledAt)}`, 635, 325);
        ctx.font = '700 52px sans-serif';
        ctx.fillText(`VAL.: ${formatDate(state.labelExpiryDate)}`, 635, 415);
        ctx.font = '400 44px sans-serif';
        ctx.fillText(`Lote: ${state.labelLot.trim()}`, 635, 500);
        ctx.fillStyle = '#555555';
        ctx.fillText(`Resp.: ${state.labelResponsibleName.trim()}`, 635, 580);
        ctx.strokeStyle = '#d5d5d5';
        ctx.lineWidth = 3;
        ctx.beginPath();
        ctx.moveTo(635, 690);
        ctx.lineTo(1285, 690);
        ctx.stroke();
        ctx.fillStyle = '#102d24';
        ctx.font = '700 58px sans-serif';
        ctx.fillText('SICE', 635, 790);
        ctx.fillStyle = '#6b746f';
        ctx.font = '400 34px sans-serif';
        ctx.fillText('Allto Al Mar · Controle de estoque', 805, 790);
        state.labelPng = canvas.toDataURL('image/png');
      }

      const preview = document.getElementById('label-preview-card');
      if (preview) {
        preview.innerHTML = labelPreviewInnerHtml(product, displayQuantity);
        refreshIcons();
        fitLabelTitle();
      }
    } catch {
      showToast('Não foi possível gerar a imagem do QR Code.');
    }
  }

  function qrScannerHtml(managementMode) {
    return `
      ${pageTitleHtml(
        'BAIXA RÁPIDA',
        'Ler código de barras ou QR',
        managementMode
          ? 'Identifique o insumo por código de barras ou QR para consultar, editar ou registrar movimentações.'
          : 'Identifique o insumo por código de barras ou QR e abra a baixa sem procurar no estoque.'
      )}
      <section class="scanner-layout">
        <article class="panel qr-reader-card">
          <div class="settings-card-head">
            <span>${icon('qr-code', 21)}</span>
            <div>
              <h2>Leitor de etiquetas</h2>
              <p>A câmera traseira oferece a leitura mais rápida.</p>
            </div>
          </div>
          <div class="qr-camera ${state.qrActive ? 'is-active' : ''}" id="qr-camera-box">
            <video id="qr-video" muted playsinline></video>
            ${
              !state.qrActive
                ? `<div class="qr-camera-placeholder">${icon('qr-code', 74, '', 1.25)}<span>Câmera desativada</span></div>`
                : ''
            }
            <div class="qr-scan-frame"><i></i></div>
          </div>
          <p class="qr-reader-status" id="qr-status-text" aria-live="polite">${esc(state.qrStatus)}</p>
          <div class="qr-reader-actions">
            <button class="btn btn--primary" type="button" id="btn-qr-start">
              ${icon('qr-code', 18)} ${state.qrActive ? 'Reiniciar câmera' : 'Ativar câmera'}
            </button>
            <button class="btn btn--simulation" type="button" id="btn-qr-simulate" ${state.qrSimulating || !state.products.length ? 'disabled' : ''}>
              ${icon('trending-up', 17)} ${state.qrSimulating ? 'Lendo etiqueta...' : 'Simular leitura'}
            </button>
            ${state.qrActive ? '<button class="btn btn--light" type="button" id="btn-qr-stop">Parar</button>' : ''}
            <label class="btn btn--light qr-upload-button">
              ${icon('upload', 17)} Ler imagem
              <input type="file" id="qr-image-input" accept="image/*" capture="environment">
            </label>
          </div>
        </article>
        <aside class="panel qr-manual-card">
          <span class="section-kicker">ALTERNATIVA MANUAL</span>
          <h2>Selecionar insumo</h2>
          <p>Se a câmera não estiver disponível, escolha o insumo para abrir o mesmo fluxo de baixa.</p>
          <label class="form-field">
            <span>Insumo</span>
            <select id="qr-manual-select">
              <option value="">Selecione um insumo</option>
              ${state.products
                .map(
                  (p) =>
                    `<option value="${esc(p.id)}" ${String(state.qrManualProduct) === String(p.id) ? 'selected' : ''}>${esc(p.name)} · ${esc(p.code)}</option>`
                )
                .join('')}
            </select>
          </label>
          <button class="btn btn--primary" type="button" id="btn-qr-manual" ${!state.qrManualProduct ? 'disabled' : ''}>
            ${managementMode ? 'Consultar insumo' : 'Registrar baixa'} ${icon('arrow-right', 17)}
          </button>
          <div class="qr-reader-tip">
            ${icon('check', 16)}
            <span>O produto será preenchido automaticamente no formulário de baixa.</span>
          </div>
        </aside>
      </section>
    `;
  }

  function findProductByCode(rawValue) {
    let reference = String(rawValue || '').trim();
    try {
      const decoded = JSON.parse(reference);
      if (typeof decoded.productCode === 'string') reference = decoded.productCode;
      else if (typeof decoded.product === 'string') reference = decoded.product;
      else if (typeof decoded.code === 'string') reference = decoded.code;
      else if (decoded.productId !== undefined) reference = String(decoded.productId);
    } catch {
      // Código simples também é aceito
    }
    return state.products.find(
      (p) => p.code.toLowerCase() === reference.toLowerCase() || String(p.id) === reference
    );
  }

  function onQrProductDetected(product) {
    const canManageInventory = state.user.role !== 'operational';
    if (canManageInventory) {
      state.selectedProductId = product.id;
    } else {
      state.movementProduct = String(product.id);
      state.modal = 'exit';
      state.modalExitReason = '';
    }
    render();
  }

  function completeQrDetection(rawValue) {
    const product = findProductByCode(rawValue);
    if (!product) {
      state.qrStatus = 'QR Code lido, mas o insumo não foi encontrado neste estoque.';
      const el = document.getElementById('qr-status-text');
      if (el) el.textContent = state.qrStatus;
      return;
    }
    stopQrScanner();
    const canManage = state.user.role !== 'operational';
    state.qrStatus = `${product.name} identificado. ${canManage ? 'Abrindo cadastro e histórico...' : 'Abrindo a baixa...'}`;
    onQrProductDetected(product);
  }

  async function startQrScanner() {
    const Detector = window.BarcodeDetector;
    if (!Detector) {
      state.qrStatus = 'A leitura automática não está disponível neste navegador. Use a seleção manual.';
      render();
      return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      state.qrStatus = 'Este dispositivo não disponibilizou acesso à câmera.';
      render();
      return;
    }
    try {
      stopQrScanner();
      const stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: { ideal: 'environment' } },
        audio: false,
      });
      state.qrStream = stream;
      state.qrActive = true;
      state.qrStatus = 'Câmera ativa. Centralize o QR Code dentro da moldura.';
      render();

      const video = document.getElementById('qr-video');
      if (!video) return;
      video.srcObject = stream;
      await video.play();

      const detector = new Detector({ formats: INVENTORY_CODE_FORMATS });
      state.qrTimer = window.setInterval(async () => {
        if (!video || video.readyState < 2 || state.qrDetecting) return;
        state.qrDetecting = true;
        try {
          const codes = await detector.detect(video);
          if (codes[0]?.rawValue) completeQrDetection(codes[0].rawValue);
        } catch {
          const el = document.getElementById('qr-status-text');
          if (el) el.textContent = 'Mantenha a etiqueta estável e bem iluminada.';
        } finally {
          state.qrDetecting = false;
        }
      }, 450);
    } catch {
      state.qrStatus = 'Não foi possível acessar a câmera. Verifique a permissão do navegador.';
      stopQrScanner();
      render();
    }
  }

  async function scanQrImage(file) {
    if (!file) return;
    const Detector = window.BarcodeDetector;
    if (!Detector) {
      state.qrStatus = 'Este navegador não consegue ler QR Code de imagens. Use a seleção manual.';
      render();
      return;
    }
    try {
      const bitmap = await createImageBitmap(file);
      const codes = await new Detector({ formats: INVENTORY_CODE_FORMATS }).detect(bitmap);
      bitmap.close();
      if (!codes[0]?.rawValue) {
        state.qrStatus = 'Nenhum QR Code foi encontrado nessa imagem.';
        render();
        return;
      }
      completeQrDetection(codes[0].rawValue);
    } catch {
      state.qrStatus = 'Não foi possível analisar essa imagem.';
      render();
    }
  }

  function simulateQrScan() {
    const demo =
      state.products.find((p) => /salmão|camarão|arroz/i.test(p.name)) || state.products[0];
    if (!demo) {
      state.qrStatus = 'Cadastre um insumo para executar a simulação.';
      render();
      return;
    }
    stopQrScanner();
    state.qrActive = true;
    state.qrSimulating = true;
    state.qrStatus = `Simulando leitura da etiqueta ${demo.code}...`;
    render();
    window.setTimeout(() => {
      state.qrSimulating = false;
      completeQrDetection(demo.code);
    }, 1500);
  }

  function settingsHtml() {
    const role = state.user.role;
    const isDark = state.theme === 'dark';
    const desc =
      role === 'owner'
        ? 'Acesso completo a compras, relatórios, equipe e operação.'
        : role === 'storekeeper'
          ? 'Acesso a compras, relatórios, cadastros e movimentações.'
          : 'Acesso à rotina operacional e criação de etiquetas.';

    return `
      ${pageTitleHtml(
        'PREFERÊNCIAS DO SISTEMA',
        'Configurações',
        'Personalize a aparência do SICE e consulte as permissões do seu perfil.'
      )}
      <section class="security-layout settings-grid">
        <article class="panel theme-card">
          <div class="settings-card-head">
            <span>${icon(isDark ? 'moon' : 'sun', 20)}</span>
            <div>
              <h2>Aparência</h2>
              <p>Escolha o tema visual mais confortável para a sua rotina.</p>
            </div>
          </div>
          <div class="theme-card-body">
            <div class="theme-option-row">
              <div class="theme-option-copy">
                <strong>Modo noturno</strong>
                <small>${
                  isDark
                    ? 'Ativado · paleta escura aplicada em toda a aplicação.'
                    : 'Desativado · exibindo o tema claro padrão do SICE.'
                }</small>
              </div>
              <button
                type="button"
                class="theme-switch ${isDark ? 'is-on' : ''}"
                role="switch"
                aria-checked="${isDark ? 'true' : 'false'}"
                aria-label="Alternar modo noturno"
                data-toggle-theme
              >
                <span class="theme-switch-thumb">${icon(isDark ? 'moon' : 'sun', 14)}</span>
              </button>
            </div>
            <div class="theme-mode-buttons">
              <button
                type="button"
                class="theme-mode-btn ${!isDark ? 'active' : ''}"
                data-set-theme="light"
              >
                ${icon('sun', 16)} Modo claro
              </button>
              <button
                type="button"
                class="theme-mode-btn ${isDark ? 'active' : ''}"
                data-set-theme="dark"
              >
                ${icon('moon', 16)} Modo noturno
              </button>
            </div>
            <p class="theme-persistence-note">
              ${icon('check', 15)} Sua preferência de tema é salva automaticamente neste navegador.
            </p>
          </div>
        </article>
        <article class="panel access-card">
          <div class="access-icon">${icon('user-round', 25)}</div>
          <span>SEU NÍVEL DE ACESSO</span>
          <h2>${esc(ROLE_LABELS[role] || role)}</h2>
          <p>${esc(state.user.name)} · ${esc(state.user.email)}</p>
          <p>${esc(desc)}</p>
          <div class="permission-list">
            <span>${icon('check', 14)} Movimentar estoque</span>
            <span>${icon('check', 14)} Criar etiquetas</span>
            ${role !== 'operational' ? `<span>${icon('check', 14)} Cadastrar e nomear insumos</span>` : ''}
            ${role === 'owner' ? `<span>${icon('check', 14)} Gerenciar equipe</span>` : ''}
          </div>
        </article>
      </section>
    `;
  }

  function supportFilesListHtml() {
    if (!state.supportFiles.length) {
      return '<p class="support-files-empty">Nenhum arquivo selecionado (opcional · até 5 arquivos de 10 MB).</p>';
    }
    return `
      <ul class="support-files-list">
        ${state.supportFiles
          .map(
            (f) => `
          <li>
            ${icon('paperclip', 15)}
            <span>${esc(f.name)}</span>
            <small>${esc(formatFileSize(f.size))}</small>
          </li>
        `
          )
          .join('')}
      </ul>
      <button type="button" class="support-clear-files" id="btn-clear-support-files">
        ${icon('x', 14)} Remover anexos selecionados
      </button>
    `;
  }

  function supportHtml() {
    const ticketsHtml = state.supportTickets.length
      ? state.supportTickets
          .map((t) => {
            const attCount = Array.isArray(t.attachments) ? t.attachments.length : 0;
            return `
              <article class="support-ticket-row">
                <div>
                  <strong>${esc(t.subject)}</strong>
                  <small>${esc(t.description.length > 110 ? `${t.description.slice(0, 110)}…` : t.description)}</small>
                  <div class="support-ticket-meta">
                    <time>${esc(formatLocalDate(t.createdAt))}</time>
                    ${attCount ? `<span>${icon('paperclip', 13)} ${attCount} ${attCount === 1 ? 'anexo' : 'anexos'}</span>` : ''}
                  </div>
                </div>
                <span class="order-status">Enviado</span>
              </article>
            `;
          })
          .join('')
      : emptyStateHtml('Nenhum chamado enviado ainda');

    return `
      ${pageTitleHtml(
        'CENTRAL DE ATENDIMENTO',
        'Ajuda e suporte',
        'Envie sua solicitação com detalhes e anexos para nossa equipe técnica.'
      )}
      <section class="support-layout">
        <article class="panel support-form-card">
          <div class="settings-card-head">
            <span>${icon('circle-help', 20)}</span>
            <div>
              <h2>Abrir chamado de suporte</h2>
              <p>Preencha os campos obrigatórios abaixo para iniciar o atendimento.</p>
            </div>
          </div>
          <div class="support-form-body">
            ${
              state.supportSuccess
                ? `
              <div class="support-success-banner" role="status" aria-live="polite">
                <span>${icon('check', 18)}</span>
                <div>
                  <strong>Mensagem enviada com sucesso</strong>
                  <p>${esc(state.supportSuccess)}</p>
                </div>
              </div>
            `
                : ''
            }
            <div
              id="support-error-banner"
              class="support-error-banner"
              role="alert"
              style="display:${state.supportError ? 'flex' : 'none'}"
            >${esc(state.supportError)}</div>
            <form data-action="createSupportTicket" class="support-form" enctype="multipart/form-data">
              <label class="form-field full-width">
                <span>Assunto *</span>
                <input
                  name="subject"
                  type="text"
                  maxlength="180"
                  required
                  placeholder="Ex.: Dúvida sobre movimentação de estoque ou erro no cadastro"
                >
              </label>
              <label class="form-field full-width">
                <span>Descrição do problema *</span>
                <textarea
                  name="description"
                  rows="6"
                  maxlength="4000"
                  required
                  placeholder="Descreva com detalhes o problema encontrado, a tela utilizada e o comportamento esperado..."
                ></textarea>
              </label>
              <div class="support-attachment-box full-width">
                <span class="support-attachment-label">Anexar arquivos</span>
                <div class="support-attachment-controls">
                  <label class="btn btn--light support-attach-btn">
                    ${icon('paperclip', 16)} Anexar arquivos
                    <input
                      id="support-files-input"
                      name="attachments[]"
                      type="file"
                      multiple
                      accept="application/pdf,image/*,.txt,.csv,.doc,.docx,.xls,.xlsx"
                    >
                  </label>
                  <small>PDF, imagens, planilhas ou documentos (máx. 10 MB por arquivo)</small>
                </div>
                <div id="support-files-wrap">
                  ${supportFilesListHtml()}
                </div>
              </div>
              <div class="support-form-footer full-width">
                <button class="btn btn--primary" type="submit">
                  ${icon('send', 16)} Enviar
                </button>
              </div>
            </form>
          </div>
        </article>
        <aside class="support-side">
          <article class="panel support-sla-card">
            <span class="section-kicker">PRAZO DE RESPOSTA</span>
            <h2>Atendimento SICE</h2>
            <p>Nossa equipe analisa cada chamado individualmente e retorna por e-mail em até <strong>48 horas</strong>.</p>
            <div class="support-sla-user">
              ${icon('mail', 16)}
              <span>Retorno para: <strong>${esc(state.user.email)}</strong></span>
            </div>
          </article>
          <article class="panel support-history-card">
            <div class="section-heading compact">
              <div>
                <span class="section-kicker">HISTÓRICO</span>
                <h2>Chamados enviados</h2>
              </div>
              <span class="count-badge">${state.supportTickets.length}</span>
            </div>
            <div class="support-tickets-list">
              ${ticketsHtml}
            </div>
          </article>
        </aside>
      </section>
    `;
  }

  // ---------------------------------------------------------------------------
  // Drawer de Detalhes do Produto & Modal de Cadastro/Movimentação
  // ---------------------------------------------------------------------------
  function productDetailDrawerHtml(product, canManage) {
    const lastEntry = state.movements.find(
      (m) =>
        m.type === 'entry' &&
        String(typeof m.product === 'object' && m.product ? m.product.id : m.product) === String(product.id)
    );
    const purchaseDate = product.purchasedAt || lastEntry?.createdAt || product.createdAt;
    const nextActive = product.active === false;

    return `
      <button class="drawer-backdrop" type="button" data-close-drawer aria-label="Fechar detalhes"></button>
      <aside class="detail-drawer">
        <div class="drawer-head">
          <div>
            <span>DETALHES DO INSUMO</span>
            <strong>${esc(product.code)}</strong>
          </div>
          <button class="icon-btn" type="button" data-close-drawer aria-label="Fechar">
            ${icon('x', 20)}
          </button>
        </div>
        <div class="detail-hero" style="--accent: ${esc(product.accent || '#376759')}">
          <span>${icon('boxes', 30)}</span>
          <div>
            <small>${esc(product.category)}</small>
            <h2>${esc(product.name)}</h2>
            <span class="status status--${esc(product.status)}">
              <i></i>
              ${needsAttention(product) ? 'Requer atenção' : 'Saudável'}
            </span>
          </div>
        </div>
        <div class="stock-highlight">
          <div>
            <small>Estoque atual</small>
            <strong>${esc(product.quantity)} <em>${esc(product.unit)}</em></strong>
          </div>
          <div>
            <small>Estoque mínimo</small>
            <strong>${esc(product.minimumStock)} <em>${esc(product.unit)}</em></strong>
          </div>
        </div>
        <div class="detail-grid">
          <div>
            <span>Comprado em</span>
            <strong>${esc(formatDate(purchaseDate))}</strong>
          </div>
          <div>
            <span>Vencimento</span>
            <strong>${esc(product.expiryDate ? formatDate(product.expiryDate) : product.expiry || 'Não informado')}</strong>
          </div>
          <div>
            <span>Localização</span>
            <strong>${esc(product.location)}</strong>
          </div>
          <div>
            <span>Último fornecedor</span>
            <strong>${esc(lastEntry?.supplier || 'Não informado')}</strong>
          </div>
          ${
            canManage
              ? `<div><span>Custo unitário</span><strong>${esc(money(product.unitCost || 0))}</strong></div>`
              : ''
          }
          <div>
            <span>Classificação do catálogo</span>
            <strong>${esc(product.catalogType ? CATALOG_TYPE_LABELS[product.catalogType] : 'Classificação pendente')}</strong>
          </div>
        </div>
        <div class="lot-card">
          <div>
            <span>Orientação PVPS</span>
            <strong>${expiresSoon(product) ? 'Utilizar primeiro' : 'Prazo confortável'}</strong>
          </div>
          <div class="lot-progress">
            <i style="width: ${expiresSoon(product) ? '86%' : '38%'}"></i>
          </div>
          <small>Confira sempre a data impressa na embalagem.</small>
        </div>
        <div class="drawer-actions ${!canManage ? 'drawer-actions--single' : ''}">
          ${
            canManage
              ? `<button class="btn btn--light" type="button" data-drawer-movement="entry">
                  ${icon('arrow-down-left', 18)} Registrar entrada
                </button>`
              : ''
          }
          <button class="btn btn--primary" type="button" data-drawer-movement="exit">
            ${icon('arrow-up-right', 18)} Registrar baixa
          </button>
        </div>
        ${
          canManage
            ? `
          <details class="record-editor product-editor">
            <summary>Editar cadastro do insumo</summary>
            <form data-action="updateProduct">
              <input type="hidden" name="id" value="${esc(product.id)}">
              <div class="form-grid">
                <label class="form-field full-width">
                  <span>Nome</span>
                  <input name="name" value="${esc(product.name)}" required>
                </label>
                <label class="form-field">
                  <span>Código</span>
                  <input name="code" value="${esc(product.code)}" required>
                </label>
                <label class="form-field">
                  <span>Categoria</span>
                  <select name="category">
                    ${INVENTORY_GROUPS.map((g) => `<option ${inventoryGroup(product) === g ? 'selected' : ''}>${esc(g)}</option>`).join('')}
                  </select>
                </label>
                <label class="form-field">
                  <span>Classificação do catálogo</span>
                  <select name="catalogType">
                    <option value="">Selecione</option>
                    ${Object.entries(CATALOG_TYPE_LABELS)
                      .map(([v, l]) => `<option value="${v}" ${product.catalogType === v ? 'selected' : ''}>${esc(l)}</option>`)
                      .join('')}
                  </select>
                </label>
                <label class="form-field">
                  <span>Local</span>
                  <input name="location" value="${esc(product.location)}" required>
                </label>
                <label class="form-field">
                  <span>Unidade</span>
                  <select name="unit">
                    ${UNITS.map((u) => `<option ${product.unit === u ? 'selected' : ''}>${u}</option>`).join('')}
                  </select>
                </label>
                <label class="form-field">
                  <span>Estoque mínimo</span>
                  <input name="minimumStock" type="number" min="0" step="0.01" value="${esc(product.minimumStock)}" required>
                </label>
                <label class="form-field">
                  <span>Custo unitário</span>
                  <input name="unitCost" type="number" min="0" step="0.01" value="${esc(product.unitCost || 0)}">
                </label>
                <label class="form-field">
                  <span>Vencimento</span>
                  <input name="expiryDate" type="date" value="${esc((product.expiryDate || '').slice(0, 10))}">
                </label>
                <label class="form-field">
                  <span>Observação</span>
                  <input name="expiry" value="${esc(product.expiry || '')}">
                </label>
              </div>
              <button class="btn btn--primary" type="submit">Salvar ${icon('arrow-right', 16)}</button>
            </form>
            <div class="record-actions">
              <button class="danger-action" type="button"
                data-toggle-active-id="${esc(product.id)}"
                data-toggle-active-next="${nextActive ? 'true' : 'false'}">
                ${nextActive ? 'Reativar insumo' : 'Inativar insumo'}
              </button>
              <button class="danger-action" type="button"
                data-confirm-action="deleteProduct"
                data-confirm-id="${esc(product.id)}"
                data-confirm-close="1"
                data-confirm-question="Remover definitivamente este insumo? Só será permitido se não houver histórico.">
                Remover definitivamente
              </button>
            </div>
          </details>
        `
            : ''
        }
      </aside>
    `;
  }

  function suggestNextProductCode() {
    let maxNum = 0;
    state.products.forEach((p) => {
      const m = String(p.code || '').match(/(\d+)$/);
      if (m) maxNum = Math.max(maxNum, Number(m[1]));
    });
    return `INS-${String(maxNum + 1).padStart(4, '0')}`;
  }

  function movementModalHtml() {
    const kind = state.modal;
    const isProduct = kind === 'product';
    const today = new Date().toISOString().slice(0, 10);
    const selectableProducts = state.products.filter(
      (p) => p.active !== false || String(p.id) === String(state.movementProduct)
    );
    const preselectedProduct = selectableProducts.find(
      (p) => String(p.id) === String(state.movementProduct)
    );
    const defaultUnit = preselectedProduct ? preselectedProduct.unit : UNITS[0];
    const suggestedCode = suggestNextProductCode();

    return `
      <div class="modal-layer" role="dialog" aria-modal="true">
        <button class="modal-backdrop" type="button" data-close-modal aria-label="Fechar"></button>
        <div class="modal-card">
          <div class="modal-head">
            <div class="modal-icon">
              ${icon(isProduct ? 'plus' : kind === 'entry' ? 'arrow-down-left' : 'arrow-up-right', 20)}
            </div>
            <div>
              <span>${isProduct ? 'CADASTRO DE INSUMO' : 'MOVIMENTAÇÃO DE ESTOQUE'}</span>
              <h2>${isProduct ? 'Novo insumo' : kind === 'entry' ? 'Registrar entrada' : 'Registrar baixa'}</h2>
            </div>
            <button class="icon-btn" type="button" data-close-modal>
              ${icon('x', 20)}
            </button>
          </div>
          <form data-action="${isProduct ? 'createProduct' : 'createMovement'}">
            <div
              id="modal-error-banner"
              class="modal-error-banner"
              role="alert"
              style="display:${state.modalError ? 'block' : 'none'}"
            >${esc(state.modalError)}</div>
            ${!isProduct ? `<input type="hidden" name="type" value="${esc(kind)}">` : ''}
            ${
              isProduct
                ? `
              <div class="form-grid">
                <label class="form-field full-width">
                  <span>Nome do novo insumo</span>
                  <input name="name" required autofocus placeholder="Digite livremente o nome do insumo">
                </label>
                <label class="form-field">
                  <span>Código</span>
                  <input name="code" required placeholder="${esc(suggestedCode)}">
                </label>
                <label class="form-field">
                  <span>Categoria</span>
                  <select name="category" required>
                    ${INVENTORY_GROUPS.map((g) => `<option value="${esc(g)}">${esc(g)}</option>`).join('')}
                  </select>
                </label>
                <label class="form-field">
                  <span>Classificação do catálogo</span>
                  <select name="catalogType" required>
                    ${Object.entries(CATALOG_TYPE_LABELS)
                      .map(([v, l]) => `<option value="${v}">${esc(l)}</option>`)
                      .join('')}
                  </select>
                </label>
                <label class="form-field">
                  <span>Local</span>
                  <input name="location" required placeholder="Ex.: Câmara Fria 01">
                </label>
                <label class="form-field">
                  <span>Quantidade inicial</span>
                  <input name="quantity" type="number" min="0" step="any" required placeholder="0">
                </label>
                <label class="form-field">
                  <span>Unidade</span>
                  <select name="unit">
                    ${UNITS.map((u) => `<option value="${u}">${u}</option>`).join('')}
                  </select>
                </label>
                <label class="form-field">
                  <span>Estoque mínimo</span>
                  <input name="minimumStock" type="number" min="0" step="any" required placeholder="0">
                </label>
                <label class="form-field">
                  <span>Custo unitário</span>
                  <input name="unitCost" type="number" min="0" step="any" placeholder="0,00">
                </label>
                <label class="form-field">
                  <span>Data da compra</span>
                  <input name="purchasedAt" type="date">
                </label>
                <label class="form-field">
                  <span>Data de vencimento</span>
                  <input name="expiryDate" type="date">
                </label>
                <label class="form-field full-width">
                  <span>Observação de validade</span>
                  <input name="expiry" placeholder="Ex.: consumir em até 30 dias">
                </label>
              </div>
            `
                : `
              <div class="form-grid">
                <label class="form-field full-width">
                  <span>Insumo</span>
                  <select name="product" id="modal-movement-product" required>
                    <option value="" disabled ${!state.movementProduct ? 'selected' : ''}>Selecione um insumo já cadastrado</option>
                    ${selectableProducts
                      .map(
                        (p) =>
                          `<option value="${esc(p.id)}" data-unit="${esc(p.unit)}" ${String(state.movementProduct) === String(p.id) ? 'selected' : ''}>${esc(p.name)} · Saldo: ${esc(p.quantity)} ${esc(p.unit)}</option>`
                      )
                      .join('')}
                  </select>
                </label>
                <label class="form-field">
                  <span>Quantidade</span>
                  <input name="quantity" type="number" min="0.001" step="any" required placeholder="0">
                </label>
                <label class="form-field">
                  <span>Unidade</span>
                  <select name="unit" id="modal-movement-unit">
                    ${UNITS.map((u) => `<option value="${u}" ${defaultUnit === u ? 'selected' : ''}>${u}</option>`).join('')}
                  </select>
                </label>
                ${
                  kind === 'entry'
                    ? `
                  <label class="form-field">
                    <span>Fornecedor</span>
                    <input name="supplier" required placeholder="Nome do fornecedor">
                  </label>
                  <label class="form-field">
                    <span>Data de recebimento</span>
                    <input name="receivedDate" type="date" value="${today}" required>
                  </label>
                  <label class="form-field">
                    <span>Custo unitário</span>
                    <input name="unitCost" type="number" min="0" step="any" required placeholder="0,00">
                  </label>
                  <label class="form-field">
                    <span>Data de vencimento</span>
                    <input name="expiryDate" type="date">
                  </label>
                  <input type="hidden" name="reason" value="Recebimento de fornecedor">
                `
                    : `
                  <label class="form-field full-width">
                    <span>Motivo da baixa</span>
                    <select name="reason" id="modal-exit-reason" required>
                      <option value="" disabled ${!state.modalExitReason ? 'selected' : ''}>Selecione o motivo</option>
                      ${['Consumo', 'Validade', 'Avaria', 'Outros']
                        .map(
                          (r) =>
                            `<option value="${r}" ${state.modalExitReason === r ? 'selected' : ''}>${r === 'Outros' ? 'Outros — especificar' : r}</option>`
                        )
                        .join('')}
                    </select>
                  </label>
                  ${
                    state.user.role === 'operational'
                      ? `
                    <label class="form-field full-width">
                      <span>Identificação operacional</span>
                      <select name="operationalArea" required>
                        <option value="" disabled selected>Selecione quem está registrando</option>
                        <option value="kitchen">Cozinha</option>
                        <option value="bar">Bar</option>
                      </select>
                    </label>
                  `
                      : ''
                  }
                  <div id="modal-other-reason-wrap" class="form-field full-width" style="display:${state.modalExitReason === 'Outros' ? 'flex' : 'none'}">
                    <label class="form-field full-width">
                      <span>Descreva o motivo</span>
                      <textarea name="otherReason" ${state.modalExitReason === 'Outros' ? 'required' : ''} rows="3" placeholder="Explique por que o produto está sendo descartado..."></textarea>
                    </label>
                  </div>
                `
                }
              </div>
            `
            }
            <div class="responsible-note">
              ${icon('user-round', 17)}
              <span>Responsável: <strong>${esc(state.user.name)} · ${esc(ROLE_LABELS[state.user.role] || state.user.role)}</strong></span>
              ${icon('check', 16)}
            </div>
            <button class="btn btn--primary" type="submit">Salvar ${icon('arrow-right', 16)}</button>
          </form>
        </div>
      </div>
    `;
  }

  // ---------------------------------------------------------------------------
  // Renderização principal do Dashboard
  // ---------------------------------------------------------------------------
  function render() {
    if (!state.user) {
      renderLoginScreen();
      return;
    }

    const canManageInventory = state.user.role !== 'operational';
    const canPurchase = state.user.role !== 'operational';
    const isOwner = state.user.role === 'owner';
    const initials = (state.user.name || 'US')
      .split(' ')
      .slice(0, 2)
      .map((part) => part[0] || '')
      .join('')
      .toUpperCase();

    const navItems = [
      { label: 'Visão geral', icon: 'layout-dashboard', show: true },
      { label: 'Estoque', icon: 'boxes', show: true },
      { label: 'Movimentações', icon: 'history', show: true },
      { label: 'Alertas', icon: 'bell', show: true },
      { label: 'Compras', icon: 'shopping-cart', show: canPurchase },
      { label: 'Relatórios', icon: 'bar-chart-3', show: canPurchase },
      { label: 'Equipe', icon: 'users-round', show: isOwner },
      { label: 'Etiquetas', icon: 'qr-code', show: true },
      { label: 'Ler QR Code', icon: 'qr-code', show: true },
    ];

    let pageContent = '';
    if (state.active === 'Visão geral') pageContent = overviewHtml(canManageInventory);
    else if (state.active === 'Estoque') pageContent = inventoryHtml(canManageInventory);
    else if (state.active === 'Movimentações') pageContent = movementsHtml(canManageInventory);
    else if (state.active === 'Alertas') pageContent = alertsHtml();
    else if (state.active === 'Compras' && canPurchase) pageContent = purchasesHtml();
    else if (state.active === 'Relatórios' && canPurchase) pageContent = reportsHtml(isOwner);
    else if (state.active === 'Equipe' && isOwner) pageContent = teamHtml();
    else if (state.active === 'Etiquetas') pageContent = labelsHtml();
    else if (state.active === 'Ler QR Code') pageContent = qrScannerHtml(canManageInventory);
    else if (state.active === 'Configurações') pageContent = settingsHtml();
    else if (state.active === 'Ajuda e suporte') pageContent = supportHtml();

    const selectedProduct = getSelectedProduct();
    const attentionCount = state.products.filter(needsAttention).length;

    const root = document.getElementById('root');
    root.innerHTML = `
      <div class="app-shell">
        <aside class="sidebar ${state.mobile ? 'sidebar--open' : ''}">
          <div class="sidebar-top">
            ${logoHtml()}
            <button class="mobile-close icon-btn" type="button" data-mobile-toggle="0" aria-label="Fechar">
              ${icon('x', 20)}
            </button>
          </div>
          <div class="restaurant-card">
            <img src="assets/img/allto-view.png" alt="Vista para o mar do restaurante Allto Al Mar" width="42" height="42">
            <div>
              <span>Unidade ativa</span>
              <strong>Allto Al Mar</strong>
              <small>Rooftop · Santos, SP</small>
            </div>
          </div>
          <nav class="main-nav">
            <span class="nav-label">OPERAÇÃO</span>
            ${navItems
              .filter((item) => item.show)
              .map(
                (item) => `
              <button type="button" class="${state.active === item.label ? 'active' : ''}" data-nav="${esc(item.label)}">
                ${icon(item.icon, 19)}
                <span>${esc(item.label)}</span>
              </button>
            `
              )
              .join('')}
            <span class="nav-label nav-label--second">SISTEMA</span>
            <button type="button" class="${state.active === 'Configurações' ? 'active' : ''}" data-nav="Configurações">
              ${icon('settings', 19)}
              <span>Configurações</span>
            </button>
            <button type="button" class="${state.active === 'Ajuda e suporte' ? 'active' : ''}" data-nav="Ajuda e suporte">
              ${icon('circle-help', 19)}
              <span>Ajuda e suporte</span>
            </button>
          </nav>
          <div class="user-card">
            <div class="avatar">${esc(initials)}</div>
            <button class="user-card-main" type="button" data-toggle-profile>
              <strong>${esc(state.user.name)}</strong>
              <span>${esc(ROLE_LABELS[state.user.role] || state.user.role)}</span>
            </button>
            <button type="button" data-toggle-profile aria-label="Abrir perfil">
              ${icon('chevron-down', 17)}
            </button>
            ${
              state.profileOpen
                ? `
              <div class="account-menu account-menu--profile">
                <span>MINHA CONTA</span>
                <div class="profile-summary">
                  <i>${esc(initials)}</i>
                  <div>
                    <strong>${esc(state.user.name)}</strong>
                    <small>${esc(state.user.email)}</small>
                  </div>
                </div>
                ${logoutButtonHtml('account-logout')}
              </div>
            `
                : ''
            }
          </div>
        </aside>

        ${state.mobile ? '<button class="menu-backdrop" type="button" data-mobile-toggle="0"></button>' : ''}

        <main>
          <header class="topbar">
            <div class="mobile-brand">
              <button class="icon-btn" type="button" data-mobile-toggle="1" aria-label="Abrir menu">
                ${icon('menu', 22)}
              </button>
              ${logoHtml({ compact: true })}
            </div>
            <div class="global-search">
              ${icon('search', 19)}
              <input id="global-search-input" value="${esc(state.query)}" placeholder="Buscar insumo ou código...">
            </div>
            <div class="top-actions">
              <span class="date-chip">${esc(ROLE_LABELS[state.user.role] || state.user.role)}</span>
              <button class="notification-btn" type="button" aria-label="Abrir alertas" data-nav="Alertas">
                ${icon('bell', 20)}
                <i>${attentionCount}</i>
              </button>
              ${logoutButtonHtml('topbar-logout', true)}
            </div>
          </header>

          <div class="page">
            ${
              state.active !== 'Visão geral'
                ? `<button class="screen-back" type="button" data-go-back>${icon('arrow-left', 17)} Voltar</button>`
                : ''
            }
            ${pageContent}
          </div>
        </main>

        <nav class="mobile-bottom-nav" aria-label="Navegação rápida">
          ${[
            ['Visão geral', 'layout-dashboard', 'Início'],
            ['Estoque', 'boxes', 'Estoque'],
            ['Ler QR Code', 'qr-code', 'Ler QR Code'],
            ['Movimentações', 'history', 'Histórico'],
            ['Configurações', 'settings', 'Config.'],
          ]
            .map(
              ([screen, ic, lbl], idx) => `
            <button type="button" class="${state.active === screen ? 'active' : ''} ${idx === 2 ? 'scan' : ''}" data-nav="${esc(screen)}">
              ${icon(ic, idx === 2 ? 22 : 19)}
              <span>${esc(lbl)}</span>
            </button>
          `
            )
            .join('')}
        </nav>

        ${state.modal ? movementModalHtml() : ''}
        ${selectedProduct ? productDetailDrawerHtml(selectedProduct, canManageInventory) : ''}
        ${state.toast ? `<div class="toast"><span>${icon('check', 15)}</span>${esc(state.toast)}</div>` : ''}
      </div>
    `;

    refreshIcons();
    if (state.active === 'Etiquetas') {
      fitLabelTitle();
      if (typeof window.requestAnimationFrame === 'function') {
        window.requestAnimationFrame(fitLabelTitle);
      }
    }
  }

  // ---------------------------------------------------------------------------
  // Delegação de eventos global (sem perda de foco em inputs)
  // ---------------------------------------------------------------------------
  const root = document.getElementById('root');

  root.addEventListener('click', async (event) => {
    const target = event.target.closest('button, a');
    if (!target) return;

    if (target.hasAttribute('data-nav')) {
      const dest = target.getAttribute('data-nav');
      if (dest === 'Ajuda e suporte') {
        state.supportError = '';
      }
      navigate(dest);
      return;
    }
    if (target.hasAttribute('data-go-back')) {
      goBack();
      return;
    }
    if (target.hasAttribute('data-toggle-theme')) {
      applyTheme(state.theme === 'dark' ? 'light' : 'dark');
      render();
      showToast(state.theme === 'dark' ? 'Modo noturno ativado.' : 'Modo claro ativado.');
      return;
    }
    if (target.hasAttribute('data-set-theme')) {
      const chosen = target.getAttribute('data-set-theme');
      if (chosen !== state.theme) {
        applyTheme(chosen);
        render();
        showToast(state.theme === 'dark' ? 'Modo noturno ativado.' : 'Modo claro ativado.');
      }
      return;
    }
    if (target.hasAttribute('data-overview-card')) {
      const kind = target.getAttribute('data-overview-card');
      if (kind === 'products') {
        state.invCategory = 'Todos';
        state.invSearch = '';
        state.query = '';
        navigate('Estoque');
      } else if (kind === 'low-stock') {
        state.alertFilter = 'stock';
        navigate('Alertas');
      } else if (kind === 'movements') {
        navigate('Movimentações');
      }
      return;
    }
    if (target.hasAttribute('data-mobile-toggle')) {
      state.mobile = target.getAttribute('data-mobile-toggle') === '1';
      render();
      return;
    }
    if (target.hasAttribute('data-toggle-profile')) {
      state.profileOpen = !state.profileOpen;
      render();
      return;
    }
    if (target.hasAttribute('data-open-modal')) {
      state.modal = target.getAttribute('data-open-modal');
      state.modalError = '';
      state.modalExitReason = '';
      render();
      return;
    }
    if (target.hasAttribute('data-close-modal')) {
      state.modal = null;
      state.modalError = '';
      state.modalExitReason = '';
      state.movementProduct = '';
      render();
      return;
    }
    if (target.hasAttribute('data-select-product')) {
      state.selectedProductId = target.getAttribute('data-select-product');
      render();
      return;
    }
    if (target.hasAttribute('data-close-drawer')) {
      state.selectedProductId = null;
      render();
      return;
    }
    if (target.hasAttribute('data-drawer-movement')) {
      const kind = target.getAttribute('data-drawer-movement');
      state.movementProduct = String(state.selectedProductId || '');
      state.selectedProductId = null;
      state.modal = kind;
      state.modalError = '';
      state.modalExitReason = '';
      render();
      return;
    }
    if (target.id === 'btn-clear-support-files') {
      const input = document.getElementById('support-files-input');
      if (input) input.value = '';
      state.supportFiles = [];
      const wrap = document.getElementById('support-files-wrap');
      if (wrap) {
        wrap.innerHTML = supportFilesListHtml();
        refreshIcons();
      }
      return;
    }
    if (target.hasAttribute('data-inv-cat')) {
      const cat = target.getAttribute('data-inv-cat');
      state.invCategory = cat === 'Todos' ? 'Todos' : state.invCategory === cat ? 'Todos' : cat;
      render();
      return;
    }
    if (target.hasAttribute('data-alert-filter')) {
      state.alertFilter = target.getAttribute('data-alert-filter');
      render();
      return;
    }
    if (target.hasAttribute('data-confirm-action')) {
      const question = target.getAttribute('data-confirm-question') || 'Confirmar ação?';
      if (!window.confirm(question)) return;
      const action = target.getAttribute('data-confirm-action');
      const id = target.getAttribute('data-confirm-id');
      const closeDrawer = target.getAttribute('data-confirm-close') === '1';
      target.disabled = true;
      target.textContent = 'Aguarde...';
      const fd = new FormData();
      fd.set('id', id);
      const res = await submitAction(action, fd);
      await handleActionResult(res, { closeDrawer });
      return;
    }
    if (target.hasAttribute('data-toggle-active-id')) {
      const nextActive = target.getAttribute('data-toggle-active-next') === 'true';
      const question = nextActive
        ? 'Reativar este insumo no catálogo?'
        : 'Inativar este insumo sem apagar seu histórico?';
      if (!window.confirm(question)) return;
      target.disabled = true;
      target.textContent = 'Aguarde...';
      const fd = new FormData();
      fd.set('id', target.getAttribute('data-toggle-active-id'));
      fd.set('active', String(nextActive));
      const res = await submitAction('setProductActive', fd);
      await handleActionResult(res, { closeDrawer: true });
      return;
    }
    if (target.id === 'btn-generate-label') {
      generateLabelPreview();
      return;
    }
    if (target.id === 'btn-print-label') {
      window.print();
      return;
    }
    if (target.id === 'btn-qr-start') {
      startQrScanner();
      return;
    }
    if (target.id === 'btn-qr-stop') {
      stopQrScanner();
      render();
      return;
    }
    if (target.id === 'btn-qr-simulate') {
      simulateQrScan();
      return;
    }
    if (target.id === 'btn-qr-manual') {
      const product = state.products.find((p) => String(p.id) === String(state.qrManualProduct));
      if (product) onQrProductDetected(product);
    }
  });

  root.addEventListener('input', (event) => {
    const el = event.target;
    if (el.id === 'global-search-input') {
      state.query = el.value;
      if (state.active === 'Estoque') {
        const wrap = document.getElementById('inventory-rows-wrap');
        if (wrap) {
          wrap.innerHTML = productRowsHtml(getFilteredInventoryProducts());
          refreshIcons();
        }
      }
      return;
    }
    if (el.id === 'inv-search-input') {
      state.invSearch = el.value;
      const wrap = document.getElementById('inventory-rows-wrap');
      if (wrap) {
        wrap.innerHTML = productRowsHtml(getFilteredInventoryProducts());
        refreshIcons();
      }
      return;
    }
    if (el.hasAttribute('data-cmv')) {
      const key = el.getAttribute('data-cmv');
      state[key] = Number(el.value) || 0;
      updateReportDynamicNumbers();
      return;
    }
    if (el.hasAttribute('data-label-field')) {
      const key = el.getAttribute('data-label-field');
      state[key] = el.value;
      state.labelQr = '';
      state.labelPng = '';
      if (key === 'labelHandledAt') {
        const expInput = document.querySelector('input[name="expiryDate"][data-label-field]');
        if (expInput) expInput.min = el.value || '';
      }
      const activeProducts = state.products.filter((p) => p.active !== false);
      const product = activeProducts.find((p) => String(p.id) === String(state.labelProductId));
      const displayQuantity = state.labelQuantity
        ? `${new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 3 }).format(Number(state.labelQuantity))} ${state.labelUnit}`
        : '—';
      const preview = document.getElementById('label-preview-card');
      if (preview) {
        preview.innerHTML = labelPreviewInnerHtml(product, displayQuantity);
        refreshIcons();
        fitLabelTitle();
      }
    }
  });

  root.addEventListener('change', (event) => {
    const el = event.target;
    if (el.id === 'inv-order-select') {
      state.invOrder = el.value;
      const wrap = document.getElementById('inventory-rows-wrap');
      if (wrap) {
        wrap.innerHTML = productRowsHtml(getFilteredInventoryProducts());
        refreshIcons();
      }
      return;
    }
    if (el.id === 'season-select') {
      state.season = el.value;
      updateReportDynamicNumbers();
      return;
    }
    if (el.id === 'modal-movement-product') {
      const selectedOpt = el.options[el.selectedIndex];
      const unitVal = selectedOpt ? selectedOpt.getAttribute('data-unit') : '';
      const unitSel = document.getElementById('modal-movement-unit');
      if (unitSel && unitVal && UNITS.includes(unitVal)) {
        unitSel.value = unitVal;
      }
      return;
    }
    if (el.id === 'support-files-input') {
      state.supportFiles = Array.from(el.files || []).map((f) => ({
        name: f.name,
        size: f.size,
      }));
      const wrap = document.getElementById('support-files-wrap');
      if (wrap) {
        wrap.innerHTML = supportFilesListHtml();
        refreshIcons();
      }
      return;
    }
    if (el.hasAttribute('data-exit-edit-reason')) {
      const movId = el.getAttribute('data-exit-edit-reason');
      const wrap = document.querySelector(`[data-exit-edit-other="${movId}"]`);
      if (wrap) {
        const show = el.value === 'Outros';
        wrap.style.display = show ? 'block' : 'none';
        const ta = wrap.querySelector('textarea');
        if (ta) ta.required = show;
      }
      return;
    }
    if (el.id === 'modal-exit-reason') {
      state.modalExitReason = el.value;
      const wrap = document.getElementById('modal-other-reason-wrap');
      if (wrap) {
        const show = el.value === 'Outros';
        wrap.style.display = show ? 'flex' : 'none';
        const ta = wrap.querySelector('textarea');
        if (ta) ta.required = show;
      }
      return;
    }
    if (el.id === 'qr-manual-select') {
      state.qrManualProduct = el.value;
      const btn = document.getElementById('btn-qr-manual');
      if (btn) btn.disabled = !el.value;
      return;
    }
    if (el.id === 'qr-image-input') {
      scanQrImage(el.files && el.files[0]);
      return;
    }
    if (el.hasAttribute('data-label-field')) {
      const key = el.getAttribute('data-label-field');
      state[key] = el.value;
      state.labelQr = '';
      state.labelPng = '';
      const activeProducts = state.products.filter((p) => p.active !== false);
      const product = activeProducts.find((p) => String(p.id) === String(state.labelProductId));
      const displayQuantity = state.labelQuantity
        ? `${new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 3 }).format(Number(state.labelQuantity))} ${state.labelUnit}`
        : '—';
      const preview = document.getElementById('label-preview-card');
      if (preview) {
        preview.innerHTML = labelPreviewInnerHtml(product, displayQuantity);
        refreshIcons();
        fitLabelTitle();
      }
    }
  });

  root.addEventListener('submit', async (event) => {
    const form = event.target.closest('form[data-action]');
    if (!form) return;
    event.preventDefault();

    const action = form.getAttribute('data-action');
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalHtml = submitBtn ? submitBtn.innerHTML : '';
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = action === 'createSupportTicket' ? 'Enviando...' : 'Salvando...';
    }
    if (state.modal) {
      state.modalError = '';
      const errEl = document.getElementById('modal-error-banner');
      if (errEl) errEl.style.display = 'none';
    }
    if (action === 'createSupportTicket') {
      state.supportError = '';
      const errEl = document.getElementById('support-error-banner');
      if (errEl) errEl.style.display = 'none';
    }

    const fd = new FormData(form);
    const result = await submitAction(action, fd);

    if (result.ok) {
      form.reset();
      if (action === 'createLabel') {
        state.labelQuantity = '';
        state.labelHandledAt = '';
        state.labelExpiryDate = '';
        state.labelLot = '';
        state.labelResponsibleName = '';
        state.labelQr = '';
        state.labelPng = '';
      }
    }

    await handleActionResult(result, { action });

    if (submitBtn && submitBtn.isConnected) {
      submitBtn.disabled = false;
      submitBtn.innerHTML = originalHtml;
      refreshIcons();
    }
  });

  if (typeof window.addEventListener === 'function') {
    let resizeRaf = null;
    window.addEventListener('resize', () => {
      if (state.active !== 'Etiquetas') return;
      if (typeof window.requestAnimationFrame === 'function') {
        if (resizeRaf !== null) window.cancelAnimationFrame(resizeRaf);
        resizeRaf = window.requestAnimationFrame(() => {
          resizeRaf = null;
          fitLabelTitle();
        });
      } else {
        fitLabelTitle();
      }
    });
    window.addEventListener('beforeprint', () => {
      if (state.active === 'Etiquetas') fitLabelTitle();
    });
  }
  if (document.fonts && document.fonts.ready && typeof document.fonts.ready.then === 'function') {
    document.fonts.ready.then(() => {
      if (state.active === 'Etiquetas') fitLabelTitle();
    });
  }

  // Inicialização
  render();
})();
