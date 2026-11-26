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

  function expiresSoon(product) {
    if (product.expiryDate) {
      const iso = String(product.expiryDate).length === 10 ? `${product.expiryDate}T00:00:00Z` : product.expiryDate;
      const days = (new Date(iso).getTime() - Date.now()) / 86400000;
      return days <= 7;
    }
    return /hoje|amanhã|^[0-7]\s*dias?/i.test(product.expiry || '');
  }

  function needsAttention(product) {
    return Number(product.quantity) <= Number(product.minimumStock) || product.status === 'critical' || expiresSoon(product);
  }

  function inventoryGroup(product) {
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

    active: 'Visão geral',
    screenHistory: [],
    query: '',
    mobile: false,
    profileOpen: false,
    selectedProductId: null,
    movementProduct: '',
    modal: null, // 'product' | 'entry' | 'exit' | null
    modalExitReason: '',
    toast: '',
    toastTimer: null,

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

  async function reloadState() {
    const res = await fetch('api/state.php', { credentials: 'same-origin' });
    if (res.status === 401) {
      window.location.href = 'index.php';
      return;
    }
    const json = await res.json();
    if (json && json.ok && json.data) {
      state.user = json.data.user;
      state.products = json.data.products || [];
      state.movements = json.data.movements || [];
      state.receipts = json.data.receipts || [];
      state.orders = json.data.orders || [];
      state.team = json.data.team || [];
      state.cmvInit = false;
      if (state.selectedProductId && !getSelectedProduct()) {
        state.selectedProductId = null;
      }
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
      const json = await res.json();
      return json;
    } catch {
      return { ok: false, error: 'Erro de comunicação com o servidor.' };
    }
  }

  async function handleActionResult(result, { closeDrawer = false } = {}) {
    showToast(result.ok ? result.message : result.error || 'Não foi possível concluir a ação.');
    if (result.ok) {
      state.modal = null;
      state.modalExitReason = '';
      state.movementProduct = '';
      if (closeDrawer) state.selectedProductId = null;
      await reloadState();
      render();
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

  function productRowsHtml(products) {
    if (!products.length) return emptyStateHtml('Nenhum insumo cadastrado');
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
    const low = state.products.filter(needsAttention);
    const totalValue = state.products.reduce(
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
            Seu estoque exige atenção em <strong>${low.length} itens</strong>. Vamos organizar as
            prioridades?
          </p>
        </div>
        <div class="welcome-actions">
          <button class="btn btn--light" type="button" data-open-modal="entry">
            ${icon('arrow-down-left', 18)} Nova entrada
          </button>
          <button class="btn btn--primary" type="button" data-open-modal="exit">
            ${icon('arrow-up-right', 18)} Registrar baixa
          </button>
        </div>
      </section>
      <section class="metrics-grid">
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
        <article class="metric-card">
          <div class="metric-icon metric-icon--green">
            ${icon('package-check', 21)}
          </div>
          <span>Total de insumos</span>
          <strong>${state.products.length}</strong>
          <small>cadastros ativos</small>
        </article>
        <article class="metric-card">
          <div class="metric-icon metric-icon--amber">
            ${icon('alert-triangle', 21)}
          </div>
          <span>Estoque mínimo</span>
          <strong>${low.length} <em>itens</em></strong>
          <small>precisam de atenção</small>
        </article>
        <article class="metric-card">
          <div class="metric-icon metric-icon--red">
            ${icon('history', 21)}
          </div>
          <span>Movimentações</span>
          <strong>${state.movements.length}</strong>
          <small>registros recentes</small>
        </article>
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
        ${productRowsHtml(low.length ? low : state.products.slice(0, 5))}
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
        <button class="btn btn--light" type="button" data-open-modal="exit">
          ${icon('arrow-up-right', 18)} Registrar baixa
        </button>
        ${
          canManage
            ? `<button class="btn btn--primary" type="button" data-open-modal="entry">${icon('arrow-down-left', 18)} Nova entrada</button>`
            : ''
        }
      </div>
    `;

    const rows = state.movements.length
      ? state.movements
          .map((m) => {
            const product = typeof m.product === 'object' && m.product ? m.product : null;
            const responsible =
              typeof m.createdBy === 'object' && m.createdBy ? m.createdBy.name : 'Responsável';
            const areaText = m.operationalArea
              ? AREA_LABELS[m.operationalArea] || m.operationalArea
              : 'Registro identificado';
            const editorHtml =
              canManage && m.type === 'entry'
                ? `
              <details class="record-editor">
                <summary>Editar entrada</summary>
                <form data-action="updateEntry">
                  <input type="hidden" name="id" value="${esc(m.id)}">
                  <div class="form-grid">
                    <label class="form-field">
                      <span>Quantidade</span>
                      <input name="quantity" type="number" min="0.01" step="0.01" value="${esc(m.quantity)}" required>
                    </label>
                    <label class="form-field">
                      <span>Unidade</span>
                      <select name="unit">
                        ${UNITS.map((u) => `<option ${m.unit === u ? 'selected' : ''}>${u}</option>`).join('')}
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
                      <input name="unitCost" type="number" min="0" step="0.01" value="${esc(m.unitCost ?? '')}">
                    </label>
                    <label class="form-field">
                      <span>Validade</span>
                      <input name="expiryDate" type="date" value="${esc((m.expiryDate || '').slice(0, 10))}">
                    </label>
                  </div>
                  <button class="btn btn--primary" type="submit">Salvar ${icon('arrow-right', 16)}</button>
                </form>
                <button class="danger-action" type="button"
                  data-confirm-action="deleteMovement"
                  data-confirm-id="${esc(m.id)}"
                  data-confirm-question="Remover esta entrada e recalcular o saldo?">
                  Remover entrada
                </button>
              </details>
            `
                : '';

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
    const expiryAlerts = state.products.filter(expiresSoon);
    const stockAlerts = state.products.filter((p) => Number(p.quantity) <= Number(p.minimumStock));
    const alerts = state.alertFilter === 'expiry' ? expiryAlerts : stockAlerts;

    const noticeHistory = [
      ...state.products.flatMap((product) => {
        const notices = [];
        const date = product.updatedAt || product.createdAt || new Date().toISOString();
        if (expiresSoon(product)) {
          notices.push({
            id: `expiry-${product.id}`,
            title: `${product.name} próximo do vencimento`,
            text: `${product.code} · ${product.expiryDate ? formatDate(product.expiryDate) : product.expiry || 'validade curta'}`,
            date,
            type: 'expiry',
          });
        }
        if (Number(product.quantity) <= Number(product.minimumStock)) {
          notices.push({
            id: `stock-${product.id}`,
            title: `${product.name} atingiu o estoque mínimo`,
            text: `${product.quantity} ${product.unit} disponíveis · mínimo de ${product.minimumStock} ${product.unit}`,
            date,
            type: 'stock',
          });
        }
        return notices;
      }),
      ...state.movements
        .filter((m) => m.type === 'exit' && /validade|avaria|outros/i.test(m.reason || ''))
        .map((m) => {
          const prod = typeof m.product === 'object' && m.product ? m.product : null;
          const by = typeof m.createdBy === 'object' && m.createdBy ? m.createdBy.name : 'responsável identificado';
          return {
            id: `movement-${m.id}`,
            title: `${m.reason}: ${prod?.name || 'insumo'}`,
            text: `${m.quantity} ${m.unit} registrados por ${by}`,
            date: m.createdAt,
            type: 'movement',
          };
        }),
    ]
      .sort((a, b) => new Date(b.date).getTime() - new Date(a.date).getTime())
      .slice(0, 20);

    return `
      ${pageTitleHtml(
        'PRIORIDADE OPERACIONAL',
        'Alertas',
        'Insumos próximos do vencimento ou abaixo da quantidade mínima.'
      )}
      <section class="alert-summary">
        <button class="${state.alertFilter === 'expiry' ? 'active' : ''}" type="button" data-alert-filter="expiry">
          <span class="alert-summary-icon expiry">${icon('clock-3', 21)}</span>
          <div>
            <span>Próximos do vencimento</span>
            <strong>${expiryAlerts.length}</strong>
            <small>prioridade PVPS</small>
          </div>
        </button>
        <button class="${state.alertFilter === 'stock' ? 'active' : ''}" type="button" data-alert-filter="stock">
          <span class="alert-summary-icon stock">${icon('alert-triangle', 21)}</span>
          <div>
            <span>Abaixo do mínimo</span>
            <strong>${stockAlerts.length}</strong>
            <small>reposição recomendada</small>
          </div>
        </button>
      </section>
      <section class="panel alert-list">
        <div class="section-heading">
          <div>
            <span class="section-kicker">AÇÃO RECOMENDADA</span>
            <h2>${state.alertFilter === 'expiry' ? 'Próximos do vencimento' : 'Perto do estoque mínimo'}</h2>
            <p>Clique em um item para consultar compra, validade, saldo e localização.</p>
          </div>
        </div>
        ${productRowsHtml(alerts)}
      </section>
      <section class="panel alert-history">
        <div class="section-heading">
          <div>
            <span class="section-kicker">ÚLTIMOS REGISTROS</span>
            <h2>Histórico de avisos</h2>
            <p>Até 20 alertas recentes de estoque, validade e descarte.</p>
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
                <span class="alert-history-icon ${esc(n.type)}">
                  ${icon(n.type === 'expiry' ? 'clock-3' : n.type === 'stock' ? 'alert-triangle' : 'history', 17)}
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
            : emptyStateHtml('Nenhum aviso registrado')
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
    const chart = [54, 72, 61, 84, 68, 91, 77, 88];
    const months = ['Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
    const lowCount = state.products.filter((p) => Number(p.quantity) <= Number(p.minimumStock)).length;

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
            ${chart
              .map(
                (h, idx) => `
              <div>
                <span style="height: ${h}%"></span>
                <i style="height: ${Math.max(25, h - 22)}%"></i>
                <small>${months[idx]}</small>
              </div>
            `
              )
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
            <div class="donut">
              <strong>${ctx.exits.length}</strong>
              <span>baixas</span>
            </div>
            <div class="donut-list">
              ${[
                ['Consumo', '44%', '#376759'],
                ['Avaria', '29%', '#b9995b'],
                ['Validade', '17%', '#a54e44'],
                ['Outros', '10%', '#c7cbc7'],
              ]
                .map(
                  ([lbl, val, color]) => `
                <div>
                  <i style="background: ${color}"></i>
                  <span>${lbl}</span>
                  <strong>${val}</strong>
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
          <form data-action="createEmployee">
            <div class="form-grid">
              <label class="form-field">
                <span>Nome</span>
                <input name="name" required>
              </label>
              <label class="form-field">
                <span>Função</span>
                <input name="function" required placeholder="Ex.: Cozinheiro, bartender...">
              </label>
              <label class="form-field">
                <span>E-mail de login</span>
                <input name="email" type="email" required>
              </label>
              <label class="form-field">
                <span>Senha inicial</span>
                <input name="password" type="password" minlength="4" required>
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
                  <form data-action="updateEmployee">
                    <input type="hidden" name="id" value="${esc(member.id)}">
                    <div class="form-grid">
                      <label class="form-field">
                        <span>Nome</span>
                        <input name="name" value="${esc(member.name)}" required>
                      </label>
                      <label class="form-field">
                        <span>Função</span>
                        <select name="function">
                          ${['Cozinheiro', 'Bartender', 'Almoxarife']
                            .map((fn) => `<option ${(member.function || 'Cozinheiro') === fn ? 'selected' : ''}>${fn}</option>`)
                            .join('')}
                        </select>
                      </label>
                      <label class="form-field">
                        <span>E-mail</span>
                        <input name="email" type="email" value="${esc(member.email)}" required>
                      </label>
                      <label class="form-field">
                        <span>Nova senha (opcional)</span>
                        <input name="password" type="password" minlength="4">
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

  function labelPreviewInnerHtml(product, displayQuantity) {
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
          <h3>${esc(product?.name || 'Nome do insumo')}</h3>
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
    if (new Date(state.labelExpiryDate).getTime() < new Date(state.labelHandledAt).getTime()) {
      showToast('A validade não pode ser anterior à manipulação.');
      return;
    }
    if (!window.QRCode) {
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

    const qrData = await window.QRCode.toDataURL(JSON.stringify(payload), {
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
      const qrImage = new window.Image();
      qrImage.src = qrData;
      await qrImage.decode();
      ctx.imageSmoothingEnabled = false;
      ctx.drawImage(qrImage, 55, 95, 500, 500);
      ctx.fillStyle = '#111111';
      ctx.textAlign = 'center';
      ctx.font = '700 48px sans-serif';
      ctx.fillText(product.code, 305, 670);

      ctx.textAlign = 'left';
      ctx.font = '700 64px sans-serif';
      ctx.fillText(product.name, 635, 130, 650);
      ctx.font = '400 48px sans-serif';
      ctx.fillText(`Quantidade: ${displayQuantity}`, 635, 220);
      ctx.fillText(`Manip.: ${formatDate(state.labelHandledAt)}`, 635, 310);
      ctx.font = '700 52px sans-serif';
      ctx.fillText(`VAL.: ${formatDate(state.labelExpiryDate)}`, 635, 400);
      ctx.font = '400 44px sans-serif';
      ctx.fillText(`Lote: ${state.labelLot.trim()}`, 635, 490);
      ctx.fillStyle = '#555555';
      ctx.fillText(`Resp.: ${state.labelResponsibleName.trim()}`, 635, 575);
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

  function accountHtml() {
    const role = state.user.role;
    const desc =
      role === 'owner'
        ? 'Acesso completo a compras, relatórios, equipe e operação.'
        : role === 'storekeeper'
          ? 'Acesso a compras, relatórios, cadastros e movimentações.'
          : 'Acesso à rotina operacional e criação de etiquetas.';

    return `
      ${pageTitleHtml(
        'SEGURANÇA DA CONTA',
        'Perfil e permissões',
        'Não há alternância de conta: cada pessoa usa seu próprio login.'
      )}
      <section class="security-layout">
        <article class="panel access-card">
          <div class="access-icon">${icon('user-round', 25)}</div>
          <span>SEU NÍVEL DE ACESSO</span>
          <h2>${esc(ROLE_LABELS[role] || role)}</h2>
          <p>${esc(desc)}</p>
          <div class="permission-list">
            <span>${icon('check', 14)} Movimentar estoque</span>
            <span>${icon('check', 14)} Criar etiquetas</span>
            ${role !== 'operational' ? `<span>${icon('check', 14)} Cadastrar e nomear insumos</span>` : ''}
            ${role === 'owner' ? `<span>${icon('check', 14)} Gerenciar equipe</span>` : ''}
          </div>
          ${logoutButtonHtml('profile-logout-button')}
        </article>
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
        <div class="drawer-actions">
          <button class="btn btn--light" type="button" data-drawer-movement="entry">
            ${icon('arrow-down-left', 18)} Entrada
          </button>
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

  function movementModalHtml() {
    const kind = state.modal;
    const isProduct = kind === 'product';
    const today = new Date().toISOString().slice(0, 10);

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
                  <input name="code" required placeholder="INS-0001">
                </label>
                <label class="form-field">
                  <span>Categoria</span>
                  <select name="category" required>
                    ${INVENTORY_GROUPS.map((g) => `<option>${esc(g)}</option>`).join('')}
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
                  <input name="location" required>
                </label>
                <label class="form-field">
                  <span>Quantidade inicial</span>
                  <input name="quantity" type="number" min="0" step="0.01" required>
                </label>
                <label class="form-field">
                  <span>Unidade</span>
                  <select name="unit">
                    ${UNITS.map((u) => `<option>${u}</option>`).join('')}
                  </select>
                </label>
                <label class="form-field">
                  <span>Estoque mínimo</span>
                  <input name="minimumStock" type="number" min="0" step="0.01" required>
                </label>
                <label class="form-field">
                  <span>Custo unitário</span>
                  <input name="unitCost" type="number" min="0" step="0.01">
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
                  <select name="product" required>
                    <option value="" disabled ${!state.movementProduct ? 'selected' : ''}>Selecione um insumo já cadastrado</option>
                    ${state.products
                      .map(
                        (p) =>
                          `<option value="${esc(p.id)}" ${String(state.movementProduct) === String(p.id) ? 'selected' : ''}>${esc(p.name)}</option>`
                      )
                      .join('')}
                  </select>
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
                ${
                  kind === 'entry'
                    ? `
                  <label class="form-field">
                    <span>Fornecedor</span>
                    <input name="supplier" required>
                  </label>
                  <label class="form-field">
                    <span>Data de recebimento</span>
                    <input name="receivedDate" type="date" value="${today}" required>
                  </label>
                  <label class="form-field">
                    <span>Custo unitário</span>
                    <input name="unitCost" type="number" min="0" step="0.01" required>
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
    else if (state.active === 'Login e senha') pageContent = accountHtml();

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
            <button type="button" class="${state.active === 'Login e senha' ? 'active' : ''}" data-nav="Login e senha">
              ${icon('settings', 19)}
              <span>Login e senha</span>
            </button>
            <button type="button" data-help-toast>
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
            ['Login e senha', 'user-round', 'Perfil'],
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
  }

  // ---------------------------------------------------------------------------
  // Delegação de eventos global (sem perda de foco em inputs)
  // ---------------------------------------------------------------------------
  const root = document.getElementById('root');

  root.addEventListener('click', async (event) => {
    const target = event.target.closest('button, a');
    if (!target) return;

    if (target.hasAttribute('data-nav')) {
      navigate(target.getAttribute('data-nav'));
      return;
    }
    if (target.hasAttribute('data-go-back')) {
      goBack();
      return;
    }
    if (target.hasAttribute('data-help-toast')) {
      showToast('Central de ajuda aberta');
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
      state.modalExitReason = '';
      render();
      return;
    }
    if (target.hasAttribute('data-close-modal')) {
      state.modal = null;
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
      state.modalExitReason = '';
      render();
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
      const activeProducts = state.products.filter((p) => p.active !== false);
      const product = activeProducts.find((p) => String(p.id) === String(state.labelProductId));
      const displayQuantity = state.labelQuantity
        ? `${new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 3 }).format(Number(state.labelQuantity))} ${state.labelUnit}`
        : '—';
      const preview = document.getElementById('label-preview-card');
      if (preview) {
        preview.innerHTML = labelPreviewInnerHtml(product, displayQuantity);
        refreshIcons();
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
      submitBtn.textContent = 'Salvando...';
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
      }
    } else if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.innerHTML = originalHtml;
      refreshIcons();
    }

    await handleActionResult(result);
  });

  // Inicialização
  render();
})();
