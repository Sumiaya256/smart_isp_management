/**
 * Header Loader - Dynamically loads and injects the shared header on all pages
 * Handles: sidebar navigation, search functionality, logout, etc.
 */

async function loadHeader() {
  try {
    console.log('[HeaderLoader] Starting...');
    const existingBodyNodes = Array.from(document.body.childNodes).filter((node) => {
      if (node.nodeType === Node.ELEMENT_NODE && node.id === 'app-header') return false;
      if (node.nodeType === Node.ELEMENT_NODE && node.tagName === 'SCRIPT') return false;
      if (node.nodeType === Node.TEXT_NODE && !node.textContent.trim()) return false;
      return true;
    });

    // Fetch the header HTML
    console.log('[HeaderLoader] Fetching header.html...');
    const response = await fetch('assets/html/header.html?v=20260326f&_t=' + Date.now());
    if (!response.ok) throw new Error('Failed to load header');
    
    const headerHTML = await response.text();
    console.log('[HeaderLoader] Header HTML fetched, length:', headerHTML.length);
    console.log('[HeaderLoader] Contains "Promee Internet":', headerHTML.includes('Promee Internet') ? 'YES' : 'NO');
    console.log('[HeaderLoader] Contains "Promee International":', headerHTML.includes('Promee International') ? 'YES (NEEDS FIX)' : 'NO');
    console.log('[HeaderLoader] Header fetched, inserting into DOM...');
    
    // Find or create a container for the header
    let container = document.getElementById('app-header');
    if (!container) {
      // If no container exists, create one at the beginning of body
      container = document.createElement('div');
      container.id = 'app-header';
      document.body.insertBefore(container, document.body.firstChild);
    }
    
    // Insert the header HTML with branding normalization
    const processedHTML = headerHTML.replace(/Promee International/g, 'Promee Internet');
    container.innerHTML = processedHTML;
    console.log('[HeaderLoader] Header inserted into #app-header');
    console.log('[HeaderLoader] Sidebar brand text:', container.querySelector('.brand')?.textContent?.trim());
    
    // Force sidebar visibility
    const sidebar = container.querySelector('.sidebar');
    if (sidebar) {
      sidebar.style.display = 'flex';
      sidebar.style.width = '250px';
      console.log('[HeaderLoader] Sidebar found and forced visible');
      console.log('[HeaderLoader] Sidebar contains:', sidebar.querySelector('.brand')?.textContent?.trim());
    } else {
      console.warn('[HeaderLoader] Sidebar element not found in injected header!');
    }

    // Defensive fix: always keep top header above page content.
    const injectedMainContent = container.querySelector('.app-shell-main-content');
    const injectedHeader = injectedMainContent ? injectedMainContent.querySelector('.app-shell-header') : null;
    const injectedPageContent = injectedMainContent ? injectedMainContent.querySelector('#app-page-content') : null;
    if (injectedMainContent && injectedHeader && injectedPageContent) {
      injectedMainContent.insertBefore(injectedHeader, injectedPageContent);
    }

    // Move existing page content into the shared placeholder.
    const pageContentTarget = document.getElementById('app-page-content');
    if (pageContentTarget) {
      pageContentTarget.innerHTML = '';

      const appendContentNode = (node) => {
        if (node.nodeType === Node.TEXT_NODE) {
          if (node.textContent.trim()) {
            pageContentTarget.appendChild(node);
          }
          return;
        }

        if (node.nodeType !== Node.ELEMENT_NODE) {
          return;
        }

        if (node.id === 'app-header') {
          return;
        }

        if (node.matches('aside.sidebar, .app-shell-header, header, #searchModal')) {
          return;
        }

        if (node.classList.contains('dashboard')) {
          Array.from(node.childNodes).forEach((childNode) => {
            if (childNode.nodeType === Node.ELEMENT_NODE && childNode.id === 'app-header') {
              return;
            }
            appendContentNode(childNode);
          });
          node.remove();
          return;
        }

        if (node.classList.contains('main-content')) {
          const directPageContent = Array.from(node.children).find(
            (child) => child.classList && (child.classList.contains('page-content') || child.classList.contains('main-inner'))
          );
          const oldPageContent = directPageContent || node.querySelector('.page-content, .main-inner');
          if (oldPageContent) {
            Array.from(oldPageContent.childNodes).forEach(appendContentNode);
          } else {
            Array.from(node.childNodes).forEach(appendContentNode);
          }
          node.remove();
          return;
        }

        if (node.classList.contains('main-inner')) {
          Array.from(node.childNodes).forEach(appendContentNode);
          return;
        }

        if (node.classList.contains('page-content')) {
          Array.from(node.childNodes).forEach(appendContentNode);
          return;
        }

        pageContentTarget.appendChild(node);
      };

      existingBodyNodes.forEach(appendContentNode);

      // Remove any leftover legacy root layout nodes after migration.
      document.querySelectorAll('body > .dashboard, body > .main-content, body > aside.sidebar, body > header').forEach((el) => {
        if (el.id !== 'app-header') {
          el.remove();
        }
      });
    }
    
    // Initialize header functionality
    initializeHeader();
  } catch (error) {
    console.error('Error loading header:', error);
  }
}

function initializeHeader() {
  console.log('[HeaderInit] Starting initialization...');

  function ensureAccessToastHost() {
    var host = document.getElementById('accessToastHost');
    if (host) return host;

    host = document.createElement('div');
    host.id = 'accessToastHost';
    host.style.position = 'fixed';
    host.style.top = '16px';
    host.style.right = '16px';
    host.style.zIndex = '3000';
    host.style.display = 'flex';
    host.style.flexDirection = 'column';
    host.style.gap = '8px';
    document.body.appendChild(host);
    return host;
  }

  var lastDeniedToastAt = 0;
  function showAccessDeniedToast(message) {
    var now = Date.now();
    if (now - lastDeniedToastAt < 800) return;
    lastDeniedToastAt = now;

    var host = ensureAccessToastHost();
    var toast = document.createElement('div');
    toast.textContent = message || 'You have no access to perform this task';
    toast.style.minWidth = '260px';
    toast.style.maxWidth = '420px';
    toast.style.padding = '10px 12px';
    toast.style.background = '#e74c3c';
    toast.style.color = '#fff';
    toast.style.borderRadius = '8px';
    toast.style.boxShadow = '0 8px 24px rgba(0, 0, 0, 0.18)';
    toast.style.fontSize = '13px';
    toast.style.lineHeight = '1.4';
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(-6px)';
    toast.style.transition = 'all 0.2s ease';
    host.appendChild(toast);

    requestAnimationFrame(function () {
      toast.style.opacity = '1';
      toast.style.transform = 'translateY(0)';
    });

    setTimeout(function () {
      toast.style.opacity = '0';
      toast.style.transform = 'translateY(-6px)';
      setTimeout(function () {
        if (toast.parentElement) toast.parentElement.removeChild(toast);
      }, 220);
    }, 3000);
  }

  function isPermissionDeniedMessage(value) {
    var msg = (value || '').toString().toLowerCase();
    return msg.indexOf('forbidden: insufficient module permission') >= 0 ||
      msg.indexOf('forbidden: insufficient role permission') >= 0;
  }

  function installGlobalPermissionNotificationHandler() {
    if (window.__permissionBubbleInstalled) return;
    window.__permissionBubbleInstalled = true;

    var originalAlert = window.alert;
    window.alert = function (message) {
      if (isPermissionDeniedMessage(message)) {
        showAccessDeniedToast('You have no access to perform this task');
        return;
      }
      return originalAlert.call(window, message);
    };

    var originalFetch = window.fetch;
    window.fetch = async function () {
      var response = await originalFetch.apply(window, arguments);
      if (response && response.status === 403) {
        try {
          var cloned = response.clone();
          var data = await cloned.json();
          if (data && isPermissionDeniedMessage(data.message || data.error || '')) {
            showAccessDeniedToast('You have no access to perform this task');
          }
        } catch (e) {
          // Ignore non-JSON bodies.
        }
      }
      return response;
    };
  }

  installGlobalPermissionNotificationHandler();

  const redirectToLogin = () => {
    window.location.replace('login.html');
  };

  const MODULE_ALIASES = {
    'purchase orders': 'Purchase',
    'hr and payroll': 'HR & Payroll',
    'support and ticketing': 'Support & Ticketing',
    'mikrotik server': 'Mikrotik Server',
    'leave management': 'Leave Management',
    'events & holidays': 'Events & Holidays',
    'task management': 'Task Management',
  };

  const MODULE_CANONICAL = {
    dashboard: 'Dashboard',
    client: 'Client',
    billing: 'Billing',
    'mikrotik server': 'Mikrotik Server',
    'hr & payroll': 'HR & Payroll',
    'hr and payroll': 'HR & Payroll',
    'leave management': 'Leave Management',
    'events & holidays': 'Events & Holidays',
    'support & ticketing': 'Support & Ticketing',
    'support and ticketing': 'Support & Ticketing',
    'task management': 'Task Management',
    attendance: 'Attendance',
    purchase: 'Purchase',
    inventory: 'Inventory',
    assets: 'Assets',
    income: 'Income',
  };

  const SUBMENU_MIN_LEVEL_BY_HREF = {
    'add_new_client.html': 'limited',
    'new_request.html': 'limited',
    'change_request.html': 'limited',
    'portal_manage.html': 'full',
    'billing.html': 'view',
    'Bulk_Client_Import.html': 'view',
    'employee_list.html': 'view',
    'add_employee.html': 'view',
    'salary_sheet.html': 'view',
    'department.html': 'view',
    'position.html': 'view',
    'payhead.html': 'view',
    'payroll.html': 'view',
    'resign_rule.html': 'view',
    'resignation.html': 'view',
    'internet_packages.html': 'view',
    'ticket_list.html': 'view',
    'new_ticket.html': 'view',
    'support_team.html': 'view',
    'ticket_reports.html': 'view',
    'service_history.html': 'view',
    'task_management.html': 'view',
    'purchase.html': 'view',
    'inventory.html': 'view',
    'assets.html': 'view',
    'income.html': 'view',
    'apply_leave.html': 'view',
    'leave_management.html': 'view',
    'daily_attendance.html': 'view',
    'attendance.html': 'view',
    'events_holidays.html': 'view',
  };

  const PAGE_ACCESS_BY_HREF = {
    'billing.html': 'Billing',
    'Bulk_Client_Import.html': 'Bulk Client Import',
    'new_request.html': 'New Request',
    'add_new_client.html': 'Add New Client',
    'client_list.html': 'Client List',
    'left_client.html': 'Left Client',
    'scheduler.html': 'Scheduler',
    'change_request.html': 'Change Request',
    'portal_manage.html': 'Portal Manage',
    'employee_list.html': 'Employee List',
    'add_employee.html': 'Add Employee',
    'salary_sheet.html': 'Salary Sheet',
    'department.html': 'Department',
    'position.html': 'Position',
    'payhead.html': 'Payhead',
    'payroll.html': 'Payroll',
    'resign_rule.html': 'Resign Rule',
    'resignation.html': 'Resignation',
    'internet_packages.html': 'Internet Packages',
    'task_management.html': 'Task Management',
    'purchase.html': 'Purchase',
    'inventory.html': 'Inventory',
    'assets.html': 'Assets',
    'income.html': 'Income',
    'ticket_list.html': 'Ticket List',
    'new_ticket.html': 'New Ticket',
    'support_team.html': 'Support Team',
    'ticket_reports.html': 'Ticket Reports',
    'service_history.html': 'Service History',
    'leave_management.html': 'Leave Management',
    'apply_leave.html': 'Apply Leave',
    'daily_attendance.html': 'Attendance',
    'attendance.html': 'Attendance',
    'events_holidays.html': 'Events & Holidays',
  };

  const LEVEL_WEIGHT = {
    none: 0,
    view: 1,
    limited: 2,
    full: 3,
  };

  function normalizeModuleName(value) {
    return (value || '')
      .toString()
      .trim()
      .replace(/\s+/g, ' ')
      .toLowerCase();
  }

  function toCanonicalModuleName(value) {
    const normalized = normalizeModuleName(value);
    return MODULE_ALIASES[normalized] || MODULE_CANONICAL[normalized] || value;
  }

  function normalizePermissionLevel(value) {
    const level = normalizeModuleName(value);
    if (level === 'full' || level === 'view' || level === 'limited' || level === 'none') {
      return level;
    }
    return 'none';
  }

  function getEffectiveModulePermissions(user) {
    const map = {};
    const fromPermissionMap = user && user.module_permissions && typeof user.module_permissions === 'object'
      ? user.module_permissions
      : null;

    if (fromPermissionMap) {
      Object.keys(fromPermissionMap).forEach((rawModuleName) => {
        const canonical = toCanonicalModuleName(rawModuleName);
        if (!canonical) return;
        const level = normalizePermissionLevel(fromPermissionMap[rawModuleName]);
        if (level !== 'none') {
          map[normalizeModuleName(canonical)] = level;
        }
      });
    }

    if (Object.keys(map).length === 0) {
      const modules = Array.isArray(user && user.access_modules) ? user.access_modules : [];
      modules.forEach((rawModuleName) => {
        const canonical = toCanonicalModuleName(rawModuleName);
        if (!canonical) return;
        map[normalizeModuleName(canonical)] = 'full';
      });
    }

    return map;
  }

  function userCanSeeSubmenu(moduleLevel, minLevel) {
    const current = LEVEL_WEIGHT[moduleLevel] || 0;
    const required = LEVEL_WEIGHT[minLevel] || LEVEL_WEIGHT.view;
    return current >= required;
  }

  function minimumLevelForSubmenuLink(href) {
    return SUBMENU_MIN_LEVEL_BY_HREF[href] || 'view';
  }

  function pagePermissionLevelForHref(permissionMap, href) {
    const pageName = PAGE_ACCESS_BY_HREF[href] || '';
    if (!pageName) return null;
    return permissionMap[normalizeModuleName(pageName)] || 'none';
  }

  function getTopLevelLabel(anchor) {
    if (!anchor) return '';
    const clone = anchor.cloneNode(true);
    clone.querySelectorAll('i').forEach((icon) => icon.remove());
    return (clone.textContent || '').trim().replace(/\s+/g, ' ');
  }

  function firstAccessibleLinkFromItem(menuItem) {
    const topAnchor = menuItem.querySelector(':scope > a');
    if (topAnchor) {
      const href = (topAnchor.getAttribute('href') || '').trim();
      if (href && href !== '#') {
        return href;
      }
    }

    const submenuLink = menuItem.querySelector('.submenu li:not([style*="display: none"]) a[href]');
    if (submenuLink) {
      return (submenuLink.getAttribute('href') || '').trim();
    }

    return '';
  }

  function applySidebarAccess(user) {
    const permissionMap = getEffectiveModulePermissions(user);
    const topItems = document.querySelectorAll('.sidebar .menu > li');

    topItems.forEach((item) => {
      const submenuLinks = item.querySelectorAll('.submenu a[href]');
      const topAnchor = item.querySelector(':scope > a');
      const topLabel = toCanonicalModuleName(getTopLevelLabel(topAnchor));
      const moduleLevel = permissionMap[normalizeModuleName(topLabel)] || 'none';
      let hasVisibleSubmenu = false;

      submenuLinks.forEach((link) => {
        const href = (link.getAttribute('href') || '').trim();
        const minLevel = minimumLevelForSubmenuLink(href);
        const pageLevel = pagePermissionLevelForHref(permissionMap, href);
        const effectiveLevel = pageLevel === null ? moduleLevel : pageLevel;
        const visible = userCanSeeSubmenu(effectiveLevel, minLevel);
        link.parentElement.style.display = visible ? '' : 'none';
        if (visible) {
          hasVisibleSubmenu = true;
        }
      });

      if (submenuLinks.length > 0) {
        item.style.display = hasVisibleSubmenu ? '' : 'none';
      } else {
        const topHref = topAnchor ? (topAnchor.getAttribute('href') || '').trim() : '';
        const topMinLevel = minimumLevelForSubmenuLink(topHref);
        const topPageLevel = pagePermissionLevelForHref(permissionMap, topHref);
        const topEffectiveLevel = topPageLevel === null ? moduleLevel : topPageLevel;
        item.style.display = userCanSeeSubmenu(topEffectiveLevel, topMinLevel) ? '' : 'none';
      }
    });
  }

  function applyPageAccessRules(user) {
    const permissionMap = getEffectiveModulePermissions(user);
    const currentPage = window.location.pathname.split('/').pop() || '';
    const pageName = PAGE_ACCESS_BY_HREF[currentPage];
    const pageLevel = pageName ? (permissionMap[normalizeModuleName(pageName)] || 'none') : 'none';
    const body = document.body;

    body.classList.remove(
      'hr-employee-list-view',
      'hr-salary-sheet-view',
      'hr-department-view',
      'hr-position-view',
      'hr-payhead-view',
      'hr-payroll-view',
      'hr-resign-rule-view',
      'hr-resignation-view',
      'hr-internet-packages-view',
      'ticket-list-view',
      'task-management-view',
      'purchase-view',
      'inventory-view',
      'assets-view',
      'income-view',
      'leave-management-view',
      'attendance-view',
      'events-holidays-view'
    );

    const rules = [];
    const addRules = (selectors, declaration) => {
      selectors.forEach((selector) => rules.push(`${selector} { ${declaration} }`));
    };

    if (currentPage === 'employee_list.html' && pageLevel !== 'full' && pageName) {
      body.classList.add('hr-employee-list-view');
      addRules([
        '.hr-employee-list-view .add-emp-btn',
        '.hr-employee-list-view button[onclick^="editEmployee("]',
        '.hr-employee-list-view button[onclick^="deleteEmployee("]'
      ], 'display: none !important;');
    }

    if (currentPage === 'salary_sheet.html' && pageLevel !== 'full' && pageName) {
      body.classList.add('hr-salary-sheet-view');
      addRules([
        '.hr-salary-sheet-view #btnApplyDefaults',
        '.hr-salary-sheet-view #btnMarkProcessed',
        '.hr-salary-sheet-view #btnResetAdjustments'
      ], 'display: none !important;');
      addRules([
        '.hr-salary-sheet-view #checkAllRows',
        '.hr-salary-sheet-view .row-input[data-field]',
        '.hr-salary-sheet-view .row-select[data-field]',
        '.hr-salary-sheet-view #taxPercent',
        '.hr-salary-sheet-view #pfPercent',
        '.hr-salary-sheet-view #defaultBonus',
        '.hr-salary-sheet-view #defaultOvertime',
        '.hr-salary-sheet-view #defaultOtherDeduction',
        '.hr-salary-sheet-view #workDays'
      ], 'pointer-events: none !important; opacity: 0.65 !important;');
    }

    if (currentPage === 'department.html' && pageLevel !== 'full' && pageName) {
      body.classList.add('hr-department-view');
      addRules([
        '.hr-department-view #departmentForm',
        '.hr-department-view #moduleOptions'
      ], 'display: none !important;');
      addRules([
        '.hr-department-view #deptTable button'
      ], 'display: none !important;');
    }

    if (currentPage === 'position.html' && pageLevel !== 'full' && pageName) {
      body.classList.add('hr-position-view');
      addRules([
        '.hr-position-view #positionForm'
      ], 'display: none !important;');
      addRules([
        '.hr-position-view #positionTable button'
      ], 'display: none !important;');
    }

    if (currentPage === 'payhead.html' && pageLevel !== 'full' && pageName) {
      body.classList.add('hr-payhead-view');
      addRules([
        '.hr-payhead-view #btnAdd',
        '.hr-payhead-view #payheadModal'
      ], 'display: none !important;');
      addRules([
        '.hr-payhead-view #payheadTable [data-edit]',
        '.hr-payhead-view #payheadTable [data-delete]'
      ], 'display: none !important;');
    }

    if (currentPage === 'payroll.html' && pageLevel !== 'full' && pageName) {
      body.classList.add('hr-payroll-view');
      addRules([
        '.hr-payroll-view #btnProcess',
        '.hr-payroll-view #btnPayAll'
      ], 'display: none !important;');
      addRules([
        '.hr-payroll-view #checkAll',
        '.hr-payroll-view .row-input[data-field]',
        '.hr-payroll-view .row-select[data-field]',
        '.hr-payroll-view #taxInput',
        '.hr-payroll-view #pfInput',
        '.hr-payroll-view #workingDaysInput'
      ], 'pointer-events: none !important; opacity: 0.65 !important;');
    }

    if (currentPage === 'resign_rule.html' && pageLevel !== 'full' && pageName) {
      body.classList.add('hr-resign-rule-view');
      addRules([
        '.hr-resign-rule-view #btnAdd',
        '.hr-resign-rule-view #ruleModal',
        '.hr-resign-rule-view #ruleTable [data-edit]',
        '.hr-resign-rule-view #ruleTable [data-delete]'
      ], 'display: none !important;');
    }

    if (currentPage === 'resignation.html' && pageLevel !== 'full' && pageName) {
      body.classList.add('hr-resignation-view');
      addRules([
        '.hr-resignation-view #btnAdd',
        '.hr-resignation-view #resignModal',
        '.hr-resignation-view #resignTable [data-edit]',
        '.hr-resignation-view #resignTable [data-status]',
        '.hr-resignation-view #resignTable [data-delete]'
      ], 'display: none !important;');
    }

    if (currentPage === 'internet_packages.html' && pageLevel !== 'full' && pageName) {
      body.classList.add('hr-internet-packages-view');
      addRules([
        '.hr-internet-packages-view #packageFormCard',
        '.hr-internet-packages-view .js-package-edit',
        '.hr-internet-packages-view .js-package-delete',
        '.hr-internet-packages-view .js-package-toggle',
        '.hr-internet-packages-view #posterStyle'
      ], 'display: none !important;');
    }

    if (currentPage === 'ticket_list.html' && pageLevel !== 'full' && pageName) {
      body.classList.add('ticket-list-view');
      addRules([
        '.ticket-list-view .action-panel'
      ], 'display: none !important;');
    }

    if (currentPage === 'task_management.html' && pageLevel !== 'full' && pageName) {
      body.classList.add('task-management-view');
      addRules([
        '.task-management-view button[onclick="openMo(\'addMo\')"]',
        '.task-management-view .btn-e',
        '.task-management-view .btn-d',
        '.task-management-view .btn-x',
        '.task-management-view #addMo .btn.btn-navy'
      ], 'display: none !important;');
    }

    if (currentPage === 'purchase.html' && pageLevel !== 'full' && pageName) {
      body.classList.add('purchase-view');
      addRules([
        '.purchase-view button[onclick="openMo(\'addMo\')"]',
        '.purchase-view .act-btns button:not(:first-child)',
        '.purchase-view #addMo .btn.primary'
      ], 'display: none !important;');
    }

    if (currentPage === 'inventory.html' && pageLevel !== 'full' && pageName) {
      body.classList.add('inventory-view');
      addRules([
        '.inventory-view button[onclick="openModal(\'addModal\')"]',
        '.inventory-view button[onclick="openStockMovementPrompt()"]',
        '.inventory-view button[onclick^="editItem("]',
        '.inventory-view button[onclick^="recordMovement("]',
        '.inventory-view button[onclick^="deleteItem("]',
        '.inventory-view #addModal .btn.btn-gold'
      ], 'display: none !important;');
    }

    if (currentPage === 'assets.html' && pageLevel !== 'full' && pageName) {
      body.classList.add('assets-view');
      addRules([
        '.assets-view button[onclick="openModal(\'addModal\')"]',
        '.assets-view button[onclick="openModal(\'assignModal\')"]',
        '.assets-view button[onclick^="editAsset("]',
        '.assets-view button[onclick^="deleteAsset("]',
        '.assets-view button[onclick^="unassignAsset("]',
        '.assets-view #assignModal .btn.btn-gold',
        '.assets-view #addModal .btn.btn-gold'
      ], 'display: none !important;');
    }

    if (currentPage === 'income.html' && pageLevel !== 'full' && pageName) {
      body.classList.add('income-view');
      addRules([
        '.income-view button[onclick="openModal(\'entryModal\')"]',
        '.income-view button[onclick^="editIncome("]',
        '.income-view button[onclick^="deleteIncome("]',
        '.income-view #entryModal .btn.btn-gold'
      ], 'display: none !important;');
    }

    if (currentPage === 'leave_management.html' && pageLevel !== 'full' && pageName) {
      body.classList.add('leave-management-view');
      addRules([
        '.leave-management-view button[onclick*="updateLeaveStatus("]'
      ], 'display: none !important;');
    }

    if ((currentPage === 'daily_attendance.html' || currentPage === 'attendance.html') && pageLevel !== 'full' && pageName) {
      body.classList.add('attendance-view');
      addRules([
        '.attendance-view #fingerBtn'
      ], 'pointer-events: none !important; opacity: 0.5 !important;');
      addRules([
        '.attendance-view .fp-label'
      ], 'opacity: 0.7 !important;');
    }

    if (currentPage === 'events_holidays.html' && pageLevel !== 'full' && pageName) {
      body.classList.add('events-holidays-view');
      addRules([
        '.events-holidays-view button[onclick="openAddModal()"]',
        '.events-holidays-view button[onclick^="editEvent("]',
        '.events-holidays-view button[onclick^="deleteEvent("]',
        '.events-holidays-view #eventModal .btn.btn-primary'
      ], 'display: none !important;');
    }

    let styleTag = document.getElementById('hr-page-access-style');
    if (!styleTag) {
      styleTag = document.createElement('style');
      styleTag.id = 'hr-page-access-style';
      document.head.appendChild(styleTag);
    }
    styleTag.textContent = rules.join('\n');
  }

  // ============================================
  // 1. AUTH CHECK
  // ============================================
  async function checkAuth() {
    try {
      const response = await fetch('backend/public/auth/me.php', {
        credentials: 'same-origin',
        cache: 'no-store',
      });

      if (!response.ok) {
        redirectToLogin();
        return;
      }

      const data = await response.json().catch(() => null);
      if (data && data.ok === false) {
        redirectToLogin();
        return;
      }

      if (data && data.user) {
        applySidebarAccess(data.user);
        applyPageAccessRules(data.user);
      }
    } catch (error) {
      redirectToLogin();
    }
  }

  // Re-check auth when page is restored from browser history.
  window.addEventListener('pageshow', function () {
    checkAuth();
  });

  window.addEventListener('popstate', function () {
    checkAuth();
  });

  checkAuth();

  // ============================================
  // 2. SIDEBAR TOGGLE
  // ============================================
  const sidebarToggle = document.querySelector('.sidebar-toggle');
  const sidebar = document.querySelector('.sidebar');

  console.log('[HeaderInit] Sidebar Toggle:', sidebarToggle ? 'FOUND' : 'NOT FOUND');
  console.log('[HeaderInit] Sidebar:', sidebar ? 'FOUND' : 'NOT FOUND');

  if (sidebarToggle && sidebar) {
    sidebarToggle.addEventListener('click', function () {
      console.log('[HeaderInit] Sidebar toggle clicked');
      sidebar.classList.toggle('active');

      if (window.innerWidth > 768) {
        if (sidebar.style.marginLeft === '-250px') {
          sidebar.style.marginLeft = '0';
        } else {
          sidebar.style.marginLeft = '-250px';
        }
      }
    });
  }

  // ============================================
  // 3. SIDEBAR DROPDOWN FUNCTIONALITY
  // ============================================
  const dropdownItems = document.querySelectorAll('.has-submenu > a');
  console.log('[HeaderInit] Found', dropdownItems.length, 'dropdown menu items');

  dropdownItems.forEach((item) => {
    item.addEventListener('click', function (e) {
      e.preventDefault();
      const parent = this.parentElement;
      parent.classList.toggle('open');

      // Close other opened menus
      document.querySelectorAll('.has-submenu.open').forEach((openItem) => {
        if (openItem !== parent) {
          openItem.classList.remove('open');
        }
      });
    });
  });

  // ============================================
  // 4. AUTO-EXPAND ACTIVE SUBMENU
  // ============================================
  const currentPage = window.location.pathname.split('/').pop() || 'index.html';
  const activeLink = document.querySelector(`.sidebar a[href="${currentPage}"]`);
  console.log('[HeaderInit] Current page:', currentPage, 'Active link found:', activeLink ? 'YES' : 'NO');
  
  if (activeLink) {
    activeLink.classList.add('active');
    const parentSubmenu = activeLink.closest('.has-submenu');
    if (parentSubmenu) {
      parentSubmenu.classList.add('open');
    }
  }

  // ============================================
  // 5. HOME BUTTON & LOGOUT BUTTON
  // ============================================
  const headerButtons = document.querySelectorAll('.header-btn');
  headerButtons.forEach((button) => {
    const text = (button.textContent || '').toLowerCase();

    // Home button - always goes to index.html
    if (text.includes('home')) {
      button.href = 'index.html';
      button.addEventListener('click', function (event) {
        event.preventDefault();
        window.location.href = 'index.html';
      });
    }

    // Logout button
    if (text.includes('log out')) {
      button.addEventListener('click', async function (event) {
        event.preventDefault();

        try {
          await fetch('backend/public/auth/logout.php', {
            method: 'POST',
            credentials: 'same-origin',
          });
        } catch (error) {
          // Ignore network errors
        }

        window.location.replace('login.html');
      });
    }
  });

  // ============================================
  // 6. UNIVERSAL SEARCH BOX FUNCTIONALITY
  // ============================================
  const searchInputs = document.querySelectorAll('input[placeholder="Search Customer"]');
  searchInputs.forEach((searchInput) => {
    let searchTimeout;

    searchInput.addEventListener('input', function (e) {
      clearTimeout(searchTimeout);
      const query = e.target.value.trim();

      if (query.length < 2) {
        const searchModal = document.getElementById('searchModal');
        if (searchModal) {
          searchModal.style.display = 'none';
        }
        return;
      }

      searchTimeout = setTimeout(() => {
        performSearch(query);
      }, 300);
    });
  });

  async function performSearch(query) {
    try {
      const response = await fetch(`backend/public/dashboard/search.php?q=${encodeURIComponent(query)}`, {
        credentials: 'same-origin',
      });
      const data = await response.json();

      const searchModal = document.getElementById('searchModal');
      if (!searchModal) {
        return;
      }

      if (data.ok && data.results.length > 0) {
        const resultsDiv = document.getElementById('searchResults');
        resultsDiv.innerHTML = data.results
          .map(
            (client) => `
          <div style="background: white; border: 1px solid #dee2e6; border-radius: 6px; padding: 15px;">
            <h3 style="margin: 0 0 10px 0; color: #333;">${escapeHtml(client.full_name)}</h3>
            <p style="margin: 5px 0; color: #666; font-size: 13px;"><strong>Code:</strong> ${escapeHtml(client.client_code)}</p>
            <p style="margin: 5px 0; color: #666; font-size: 13px;"><strong>Phone:</strong> ${escapeHtml(client.phone)}</p>
            <p style="margin: 5px 0; color: #666; font-size: 13px;"><strong>Email:</strong> ${escapeHtml(client.email)}</p>
            <p style="margin: 5px 0; color: #666; font-size: 13px;"><strong>Package:</strong> ${escapeHtml(client.package_name || 'N/A')}</p>
            <p style="margin: 5px 0; color: #666; font-size: 13px;"><strong>Status:</strong> <span style="background: #d4edda; color: #155724; padding: 2px 8px; border-radius: 3px;">${escapeHtml(client.status)}</span></p>
          </div>
        `
          )
          .join('');

        searchModal.style.display = 'block';
      } else {
        const resultsDiv = document.getElementById('searchResults');
        resultsDiv.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; padding: 20px; color: #999;">No results found</div>';
        searchModal.style.display = 'block';
      }
    } catch (error) {
      console.error('Search failed:', error);
    }
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  // Make closeSearchModal available globally
  window.closeSearchModal = function () {
    const searchModal = document.getElementById('searchModal');
    if (searchModal) {
      searchModal.style.display = 'none';
    }
    const searchInputs = document.querySelectorAll('input[placeholder="Search Customer"]');
    searchInputs.forEach((input) => {
      input.value = '';
    });
  };

  // FINAL VERIFICATION
  console.log('[HeaderInit] ========== INITIALIZATION COMPLETE ==========');
  console.log('[HeaderInit] Page brand text:', document.querySelector('.brand')?.textContent?.trim());
  console.log('[HeaderInit] Sidebar visible:', document.querySelector('.sidebar') ? 'YES' : 'NO');
  console.log('[HeaderInit] Old sidebar check (Promee International):', document.body.innerHTML.includes('Promee International') ? 'FOUND (BAD)' : 'NOT FOUND (GOOD)');
  console.log('[HeaderInit] ================================================');
}

// Load header when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', loadHeader);
} else {
  loadHeader();
}
