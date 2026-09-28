// RBAC Configuration for RDO|MMS
// Role-Based Access Control System

const rolePermissions = {
    // Level 1: Executive - Full System Access
    'mkurugenzi_mtendaji': {
        level: 1,
        name: 'Executive Director',
        permissions: ['all'],
        modules: {
            users: ['create', 'read', 'update', 'delete', 'admin'],
            beneficiaries: ['create', 'read', 'update', 'delete', 'admin'],
            planning: ['create', 'read', 'update', 'delete', 'admin'],
            finance: ['create', 'read', 'update', 'delete', 'admin'],
            departments: ['create', 'read', 'update', 'delete', 'admin'],
            reports: ['create', 'read', 'update', 'delete', 'admin'],
            system: ['create', 'read', 'update', 'delete', 'admin']
        },
        navigation: ['all'],
        dataAccess: 'all'
    },

    // Level 2: Management - Regional/Departmental Access
    'mkuu_wa_kituo': {
        level: 2,
        name: 'Center Manager',
        permissions: ['management'],
        modules: {
            users: ['read', 'update'],
            beneficiaries: ['create', 'read', 'update', 'delete'],
            planning: ['create', 'read', 'update', 'delete'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read', 'create'],
            system: []
        },
        navigation: ['users', 'beneficiaries', 'planning', 'finance', 'reports'],
        dataAccess: 'regional'
    },

    'mkuu_wa_idara': {
        level: 2,
        name: 'Department Manager',
        permissions: ['management'],
        modules: {
            users: ['read', 'update'],
            beneficiaries: ['create', 'read', 'update', 'delete'],
            planning: ['create', 'read', 'update', 'delete'],
            finance: ['read'],
            departments: ['read', 'update'],
            reports: ['read', 'create'],
            system: []
        },
        navigation: ['users', 'beneficiaries', 'planning', 'finance', 'reports'],
        dataAccess: 'departmental'
    },

    'mhasibu': {
        level: 2,
        name: 'Accountant',
        permissions: ['finance'],
        modules: {
            users: ['read'],
            beneficiaries: ['read'],
            planning: ['read'],
            finance: ['create', 'read', 'update', 'delete'],
            departments: ['read'],
            reports: ['read', 'create'],
            system: []
        },
        navigation: ['finance', 'reports'],
        dataAccess: 'financial'
    },

    'afisa_rasilimali_watu': {
        level: 2,
        name: 'Human Resources Officer',
        permissions: ['hr'],
        modules: {
            users: ['create', 'read', 'update', 'delete'],
            beneficiaries: ['read'],
            planning: ['read'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read', 'create'],
            system: []
        },
        navigation: ['users', 'reports'],
        dataAccess: 'hr'
    },

    // Level 3: Technical Officers - Functional Access
    'afisa_miradi': {
        level: 3,
        name: 'Project Officer',
        permissions: ['projects'],
        modules: {
            users: ['read'],
            beneficiaries: ['create', 'read', 'update', 'delete'],
            planning: ['create', 'read', 'update', 'delete'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read', 'create'],
            system: []
        },
        navigation: ['beneficiaries', 'planning', 'reports'],
        dataAccess: 'project'
    },

    'afisa_maendeleo_ya_jamii': {
        level: 3,
        name: 'Community Development Officer',
        permissions: ['community'],
        modules: {
            users: ['read'],
            beneficiaries: ['create', 'read', 'update', 'delete'],
            planning: ['read'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read', 'create'],
            system: []
        },
        navigation: ['beneficiaries', 'reports'],
        dataAccess: 'community'
    },

    'afisa_kilimo_mifugo': {
        level: 3,
        name: 'Agriculture and Livestock Officer',
        permissions: ['agriculture'],
        modules: {
            users: ['read'],
            beneficiaries: ['create', 'read', 'update', 'delete'],
            planning: ['read'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read', 'create'],
            system: []
        },
        navigation: ['beneficiaries', 'reports'],
        dataAccess: 'agriculture'
    },

    'afisa_elimu_mkufunzi': {
        level: 3,
        name: 'Education and Training Officer',
        permissions: ['education'],
        modules: {
            users: ['read'],
            beneficiaries: ['create', 'read', 'update', 'delete'],
            planning: ['read'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read', 'create'],
            system: []
        },
        navigation: ['beneficiaries', 'reports'],
        dataAccess: 'education'
    },

    'afisa_ufuatiliaji_tathmini': {
        level: 3,
        name: 'Monitoring and Evaluation Officer',
        permissions: ['monitoring'],
        modules: {
            users: ['read'],
            beneficiaries: ['read'],
            planning: ['read', 'update'],
            finance: ['read'],
            departments: ['read'],
            reports: ['create', 'read', 'update', 'delete'],
            system: []
        },
        navigation: ['planning', 'reports'],
        dataAccess: 'monitoring'
    },

    'afisa_takwimu': {
        level: 3,
        name: 'Statistics Officer',
        permissions: ['statistics'],
        modules: {
            users: ['read'],
            beneficiaries: ['read'],
            planning: ['read'],
            finance: ['read'],
            departments: ['read'],
            reports: ['create', 'read', 'update', 'delete'],
            system: []
        },
        navigation: ['reports'],
        dataAccess: 'statistics'
    },

    // Level 4: Field Officers - Limited Access
    'afisa_huduma_za_jamii': {
        level: 4,
        name: 'Community Services Officer',
        permissions: ['field'],
        modules: {
            users: ['read'],
            beneficiaries: ['create', 'read', 'update'],
            planning: ['read'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read'],
            system: []
        },
        navigation: ['beneficiaries'],
        dataAccess: 'field'
    },

    'afisa_maji': {
        level: 4,
        name: 'Water Officer',
        permissions: ['field'],
        modules: {
            users: ['read'],
            beneficiaries: ['create', 'read', 'update'],
            planning: ['read'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read'],
            system: []
        },
        navigation: ['beneficiaries'],
        dataAccess: 'field'
    },

    'mratibu_wa_vikundi': {
        level: 4,
        name: 'Groups Coordinator',
        permissions: ['field'],
        modules: {
            users: ['read'],
            beneficiaries: ['create', 'read', 'update'],
            planning: ['read'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read'],
            system: []
        },
        navigation: ['beneficiaries'],
        dataAccess: 'field'
    },

    'afisa_uwanja': {
        level: 4,
        name: 'Field Officer',
        permissions: ['field'],
        modules: {
            users: ['read'],
            beneficiaries: ['create', 'read', 'update'],
            planning: ['read'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read'],
            system: []
        },
        navigation: ['beneficiaries'],
        dataAccess: 'field'
    },

    'msaidizi_wa_uwanja': {
        level: 4,
        name: 'Field Assistant',
        permissions: ['basic'],
        modules: {
            users: ['read'],
            beneficiaries: ['read'],
            planning: ['read'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read'],
            system: []
        },
        navigation: ['beneficiaries'],
        dataAccess: 'basic'
    },

    // Level 5: Support Roles - Limited Access
    'katibu_tawala': {
        level: 5,
        name: 'Administrative Secretary',
        permissions: ['administrative'],
        modules: {
            users: ['read', 'update'],
            beneficiaries: ['read'],
            planning: ['read'],
            finance: ['read'],
            departments: ['read', 'update'],
            reports: ['read', 'create'],
            system: []
        },
        navigation: ['users', 'departments', 'reports'],
        dataAccess: 'administrative'
    },

    'katibu_mtendaji': {
        level: 5,
        name: 'Executive Secretary',
        permissions: ['administrative'],
        modules: {
            users: ['read', 'update'],
            beneficiaries: ['read'],
            planning: ['read', 'update'],
            finance: ['read'],
            departments: ['read', 'update'],
            reports: ['read', 'create', 'update'],
            system: []
        },
        navigation: ['users', 'planning', 'departments', 'reports'],
        dataAccess: 'administrative'
    },

    'meneja_programu': {
        level: 3,
        name: 'Program Manager',
        permissions: ['program_management'],
        modules: {
            users: ['read', 'update'],
            beneficiaries: ['create', 'read', 'update', 'delete'],
            planning: ['create', 'read', 'update', 'delete'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read', 'create', 'update', 'delete'],
            system: []
        },
        navigation: ['beneficiaries', 'planning', 'reports'],
        dataAccess: 'program'
    },

    'kocha_mafunzo': {
        level: 4,
        name: 'Training Officer',
        permissions: ['training'],
        modules: {
            users: ['read'],
            beneficiaries: ['create', 'read', 'update'],
            planning: ['read'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read', 'create'],
            system: []
        },
        navigation: ['beneficiaries', 'reports'],
        dataAccess: 'training'
    },

    'meneja_mawasiliano': {
        level: 3,
        name: 'Communications Manager',
        permissions: ['communication'],
        modules: {
            users: ['read'],
            beneficiaries: ['read'],
            planning: ['read'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read', 'create'],
            system: []
        },
        navigation: ['reports'],
        dataAccess: 'communication'
    },

    'afisa_misaa': {
        level: 4,
        name: 'Religious Affairs Officer',
        permissions: ['religious'],
        modules: {
            users: ['read'],
            beneficiaries: ['create', 'read', 'update'],
            planning: ['read'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read', 'create'],
            system: []
        },
        navigation: ['beneficiaries', 'reports'],
        dataAccess: 'religious'
    },

    'meneja_misaa': {
        level: 3,
        name: 'Religious Affairs Manager',
        permissions: ['religious_management'],
        modules: {
            users: ['read', 'update'],
            beneficiaries: ['create', 'read', 'update', 'delete'],
            planning: ['create', 'read', 'update'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read', 'create', 'update', 'delete'],
            system: []
        },
        navigation: ['beneficiaries', 'planning', 'reports'],
        dataAccess: 'religious_management'
    },

    'afisa_matukio': {
        level: 4,
        name: 'Events Officer',
        permissions: ['events'],
        modules: {
            users: ['read'],
            beneficiaries: ['create', 'read', 'update'],
            planning: ['read'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read', 'create'],
            system: []
        },
        navigation: ['beneficiaries', 'reports'],
        dataAccess: 'events'
    },

    'meneja_matukio': {
        level: 3,
        name: 'Events Manager',
        permissions: ['event_management'],
        modules: {
            users: ['read', 'update'],
            beneficiaries: ['create', 'read', 'update', 'delete'],
            planning: ['create', 'read', 'update'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read', 'create', 'update', 'delete'],
            system: []
        },
        navigation: ['beneficiaries', 'planning', 'reports'],
        dataAccess: 'event_management'
    },

    'afisa_it': {
        level: 2,
        name: 'IT Officer',
        permissions: ['it_support'],
        modules: {
            users: ['create', 'read', 'update', 'delete'],
            beneficiaries: ['read'],
            planning: ['read'],
            finance: ['read'],
            departments: ['read', 'update'],
            reports: ['read'],
            system: ['create', 'read', 'update', 'delete']
        },
        navigation: ['users', 'departments', 'system'],
        dataAccess: 'it_support'
    },

    'meneja_it': {
        level: 2,
        name: 'IT Manager',
        permissions: ['it_management'],
        modules: {
            users: ['create', 'read', 'update', 'delete'],
            beneficiaries: ['read'],
            planning: ['read'],
            finance: ['read'],
            departments: ['create', 'read', 'update', 'delete'],
            reports: ['read', 'create'],
            system: ['create', 'read', 'update', 'delete', 'admin']
        },
        navigation: ['users', 'departments', 'system', 'reports'],
        dataAccess: 'it_management'
    },

    'afisa_usalama': {
        level: 2,
        name: 'Security Officer',
        permissions: ['security'],
        modules: {
            users: ['read', 'update'],
            beneficiaries: ['read'],
            planning: ['read'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read'],
            system: ['read', 'update']
        },
        navigation: ['users', 'system'],
        dataAccess: 'security'
    },

    'meneja_usalama': {
        level: 2,
        name: 'Security Manager',
        permissions: ['security_management'],
        modules: {
            users: ['create', 'read', 'update', 'delete'],
            beneficiaries: ['read'],
            planning: ['read'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read', 'create'],
            system: ['create', 'read', 'update', 'delete', 'admin']
        },
        navigation: ['users', 'system', 'reports'],
        dataAccess: 'security_management'
    },

    'afisa_matangazo': {
        level: 4,
        name: 'Public Relations Officer',
        permissions: ['public_relations'],
        modules: {
            users: ['read'],
            beneficiaries: ['read'],
            planning: ['read'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read', 'create'],
            system: []
        },
        navigation: ['reports'],
        dataAccess: 'public_relations'
    },

    'meneja_matangazo': {
        level: 3,
        name: 'Public Relations Manager',
        permissions: ['public_relations_management'],
        modules: {
            users: ['read', 'update'],
            beneficiaries: ['read'],
            planning: ['read', 'update'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read', 'create', 'update', 'delete'],
            system: []
        },
        navigation: ['planning', 'reports'],
        dataAccess: 'public_relations_management'
    },

    'afisa_ugavi': {
        level: 3,
        name: 'Logistics Officer',
        permissions: ['logistics'],
        modules: {
            users: ['read'],
            beneficiaries: ['read'],
            planning: ['read'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read', 'create'],
            system: []
        },
        navigation: ['reports'],
        dataAccess: 'logistics'
    },

    'meneja_ugavi': {
        level: 3,
        name: 'Logistics Manager',
        permissions: ['logistics_management'],
        modules: {
            users: ['read', 'update'],
            beneficiaries: ['read'],
            planning: ['read', 'update'],
            finance: ['read'],
            departments: ['read', 'update'],
            reports: ['read', 'create', 'update', 'delete'],
            system: []
        },
        navigation: ['departments', 'planning', 'reports'],
        dataAccess: 'logistics_management'
    },

    'afisa_uchukuzi': {
        level: 4,
        name: 'Transport Officer',
        permissions: ['transport'],
        modules: {
            users: ['read'],
            beneficiaries: ['read'],
            planning: ['read'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read'],
            system: []
        },
        navigation: ['beneficiaries'],
        dataAccess: 'transport'
    },

    'meneja_uchukuzi': {
        level: 3,
        name: 'Transport Manager',
        permissions: ['transport_management'],
        modules: {
            users: ['read', 'update'],
            beneficiaries: ['read'],
            planning: ['read', 'update'],
            finance: ['read'],
            departments: ['read', 'update'],
            reports: ['read', 'create'],
            system: []
        },
        navigation: ['departments', 'planning', 'reports'],
        dataAccess: 'transport_management'
    },

    'afisa_upatikanaji_wa_vifaa': {
        level: 4,
        name: 'Procurement Officer',
        permissions: ['procurement'],
        modules: {
            users: ['read'],
            beneficiaries: ['read'],
            planning: ['read'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read', 'create'],
            system: []
        },
        navigation: ['reports'],
        dataAccess: 'procurement'
    },

    'meneja_upatikanaji_wa_vifaa': {
        level: 3,
        name: 'Procurement Manager',
        permissions: ['procurement_management'],
        modules: {
            users: ['read', 'update'],
            beneficiaries: ['read'],
            planning: ['read', 'update'],
            finance: ['read'],
            departments: ['read', 'update'],
            reports: ['read', 'create', 'update', 'delete'],
            system: []
        },
        navigation: ['departments', 'planning', 'reports'],
        dataAccess: 'procurement_management'
    },

    'afisa_mazingira': {
        level: 4,
        name: 'Environment Officer',
        permissions: ['environmental'],
        modules: {
            users: ['read'],
            beneficiaries: ['create', 'read', 'update'],
            planning: ['read'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read', 'create'],
            system: []
        },
        navigation: ['beneficiaries', 'reports'],
        dataAccess: 'environmental'
    },

    'meneja_mazingira': {
        level: 3,
        name: 'Environment Manager',
        permissions: ['environmental_management'],
        modules: {
            users: ['read', 'update'],
            beneficiaries: ['create', 'read', 'update', 'delete'],
            planning: ['create', 'read', 'update'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read', 'create', 'update', 'delete'],
            system: []
        },
        navigation: ['beneficiaries', 'planning', 'reports'],
        dataAccess: 'environmental_management'
    },

    'afisa_afya': {
        level: 4,
        name: 'Health Officer',
        permissions: ['health'],
        modules: {
            users: ['read'],
            beneficiaries: ['create', 'read', 'update'],
            planning: ['read'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read', 'create'],
            system: []
        },
        navigation: ['beneficiaries', 'reports'],
        dataAccess: 'health'
    },

    'meneja_afya': {
        level: 3,
        name: 'Health Manager',
        permissions: ['health_management'],
        modules: {
            users: ['read', 'update'],
            beneficiaries: ['create', 'read', 'update', 'delete'],
            planning: ['create', 'read', 'update'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read', 'create', 'update', 'delete'],
            system: []
        },
        navigation: ['beneficiaries', 'planning', 'reports'],
        dataAccess: 'health_management'
    },

    'afisa_maji_na_usafi': {
        level: 4,
        name: 'Water and Sanitation Officer',
        permissions: ['water_sanitation'],
        modules: {
            users: ['read'],
            beneficiaries: ['create', 'read', 'update'],
            planning: ['read'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read', 'create'],
            system: []
        },
        navigation: ['beneficiaries', 'reports'],
        dataAccess: 'water_sanitation'
    },

    'meneja_maji_na_usafi': {
        level: 3,
        name: 'Water and Sanitation Manager',
        permissions: ['water_sanitation_management'],
        modules: {
            users: ['read', 'update'],
            beneficiaries: ['create', 'read', 'update', 'delete'],
            planning: ['create', 'read', 'update'],
            finance: ['read'],
            departments: ['read'],
            reports: ['read', 'create', 'update', 'delete'],
            system: []
        },
        navigation: ['beneficiaries', 'planning', 'reports'],
        dataAccess: 'water_sanitation_management'
    }
};

// Navigation Menu Configuration
const navigationConfig = {
    'users': {
        id: 'users',
        title: 'User Management',
        icon: 'fas fa-users',
        requiredLevel: 2,
        dropdown: [
            { id: 'add_user', title: 'Add User', requiredLevel: 2 },
            { id: 'view_users', title: 'User List', requiredLevel: 2 },
            { id: 'user_permissions', title: 'User Permissions', requiredLevel: 1 },
            { id: 'user_activity', title: 'User Activity', requiredLevel: 2 }
        ]
    },
    'departments': {
        id: 'departments',
        title: 'Department Management',
        icon: 'fas fa-building',
        requiredLevel: 2,
        dropdown: [
            { id: 'add_department', title: 'Create Department', requiredLevel: 2 },
            { id: 'departments', title: 'Department List', requiredLevel: 2 },
            { id: 'view_departments', title: 'Department Details', requiredLevel: 2 }
        ]
    },
    'planning': {
        id: 'planning',
        title: 'Planning and Monitoring',
        icon: 'fas fa-tasks',
        requiredLevel: 3,
        dropdown: [
            { id: 'weekly_plan', title: 'Weekly Work Plan', requiredLevel: 3 },
            { id: 'monthly_plan', title: 'Monthly Work Plan', requiredLevel: 3 },
            { id: 'quarterly_plan', title: 'Quarterly Work Plan', requiredLevel: 3 },
            { id: 'annual_plan', title: 'Annual Work Plan', requiredLevel: 3 },
            { id: 'authorization', title: 'Authorization', requiredLevel: 2 },
            { id: 'report_authorization', title: 'Report Authorization', requiredLevel: 2 }
        ]
    },
    'beneficiaries': {
        id: 'beneficiaries',
        title: 'Beneficiaries',
        icon: 'fas fa-users',
        requiredLevel: 3,
        dropdown: [
            { id: 'register_beneficiary', title: 'Register Beneficiary', requiredLevel: 3 },
            { id: 'view_beneficiaries', title: 'Beneficiary List', requiredLevel: 3 },
            { id: 'beneficiary_profile', title: 'Beneficiary Details', requiredLevel: 3 },
            { id: 'service_tracking', title: 'Service Tracking', requiredLevel: 3 },
            { id: 'beneficiary_reports', title: 'Beneficiary Reports', requiredLevel: 3 }
        ]
    },
    'finance': {
        id: 'finance',
        title: 'Finance',
        icon: 'fas fa-coins',
        requiredLevel: 2,
        dropdown: [
            { id: 'budget_allocation', title: 'Budget Allocation', requiredLevel: 2 },
            { id: 'department_budgets', title: 'Department Budgets', requiredLevel: 2 },
            { id: 'expense_tracking', title: 'Expense Tracking', requiredLevel: 2 },
            { id: 'financial_reports', title: 'Financial Reports', requiredLevel: 2 },
            { id: 'budget_authorization', title: 'Budget Authorization', requiredLevel: 2 }
        ]
    },
    'reports': {
        id: 'reports',
        title: 'Reports',
        icon: 'fas fa-chart-bar',
        requiredLevel: 3,
        dropdown: [
            { id: 'monthlyrpt', title: 'Monthly Reports', requiredLevel: 3 },
            { id: 'quarterlyrpt', title: 'Quarterly Report', requiredLevel: 3 },
            { id: 'annualrpt', title: 'Annual Report', requiredLevel: 3 },
            { id: 'weeklyrpt', title: 'Weekly Report', requiredLevel: 3 },
            { id: 'semiannualrpt', title: 'Semiannual Report', requiredLevel: 3 }
        ]
    },
    'system': {
        id: 'system',
        title: 'System Management',
        icon: 'fas fa-cogs',
        requiredLevel: 1,
        dropdown: [
            { id: 'settings', title: 'System Settings', requiredLevel: 1 },
            { id: 'backup', title: 'Backup', requiredLevel: 1 },
            { id: 'audit', title: 'System Audit', requiredLevel: 1 }
        ]
    }
};

// RBAC Helper Functions
function hasPermission(userRole, permission) {
    const role = rolePermissions[userRole];
    if (!role) return false;
    
    return role.permissions.includes(permission) || role.permissions.includes('all');
}

function hasModuleAccess(userRole, module, action = 'read') {
    const role = rolePermissions[userRole];
    if (!role) return false;
    
    return role.modules[module] && 
           (role.modules[module].includes(action) || role.modules[module].includes('all'));
}

function canAccessNavigation(userRole, navId) {
    const role = rolePermissions[userRole];
    if (!role) return false;
    
    return role.navigation.includes('all') || role.navigation.includes(navId);
}

function getAccessibleNavigation(userRole) {
    const role = rolePermissions[userRole];
    if (!role) return [];
    
    if (role.navigation.includes('all')) {
        return Object.keys(navigationConfig);
    }
    
    return role.navigation;
}

function filterNavigationByRole(userRole) {
    const accessibleNav = getAccessibleNavigation(userRole);
    const filteredNav = {};
    
    accessibleNav.forEach(navId => {
        if (navigationConfig[navId]) {
            filteredNav[navId] = navigationConfig[navId];
            
            // Filter dropdown items
            if (filteredNav[navId].dropdown) {
                filteredNav[navId].dropdown = filteredNav[navId].dropdown.filter(item => {
                    const role = rolePermissions[userRole];
                    return role && (role.level <= item.requiredLevel || role.level === 1);
                });
            }
        }
    });
    
    return filteredNav;
}

function checkPageAccess(requiredRole = null, requiredLevel = null) {
    const currentUser = JSON.parse(localStorage.getItem('currentUser'));
    if (!currentUser) {
        window.location.href = (window.RDOMMSRBAC && typeof RDOMMSRBAC.getLandingPage === 'function')
            ? RDOMMSRBAC.getLandingPage()
            : 'index.php';
        return false;
    }
    
    const userRole = currentUser.role;
    const role = rolePermissions[userRole];
    
    if (!role) {
        console.error('Invalid user role:', userRole);
        return false;
    }
    
    // Check specific role requirement
    if (requiredRole && userRole !== requiredRole && role.level !== 1) {
        showAccessDenied();
        return false;
    }
    
    // Check level requirement
    if (requiredLevel && role.level > requiredLevel && role.level !== 1) {
        showAccessDenied();
        return false;
    }
    
    return true;
}

function showAccessDenied() {
    alert('You do not have permission to access this page. Please contact your manager.');
    window.history.back();
}

// Initialize RBAC on page load
function initializeRBAC() {
    const currentUser = JSON.parse(localStorage.getItem('currentUser'));
    if (!currentUser) return;
    
    const userRole = currentUser.role;
    const role = rolePermissions[userRole];
    
    if (!role) {
        console.error('Invalid user role:', userRole);
        return;
    }
    
    // Update navigation based on role
    updateNavigationByRole(userRole);
    
    // Update user interface
    updateUIByRole(userRole);
    
    console.log('RBAC initialized for role:', userRole, 'Level:', role.level);
}

function updateNavigationByRole(userRole) {
    const filteredNav = filterNavigationByRole(userRole);
    
    // Hide/show navigation sections
    Object.keys(navigationConfig).forEach(navId => {
        const navElement = document.querySelector(`[data-nav="${navId}"]`);
        if (navElement) {
            if (filteredNav[navId]) {
                navElement.style.display = 'block';
                
                // Update dropdown items
                const dropdown = navElement.querySelector('.dropdown-menu');
                if (dropdown && filteredNav[navId].dropdown) {
                    const dropdownItems = dropdown.querySelectorAll('.dropdown-item');
                    dropdownItems.forEach(item => {
                        const itemId = item.getAttribute('data-item');
                        const itemExists = filteredNav[navId].dropdown.find(d => d.id === itemId);
                        item.style.display = itemExists ? 'block' : 'none';
                    });
                }
            } else {
                navElement.style.display = 'none';
            }
        }
    });
}

function updateUIByRole(userRole) {
    const role = rolePermissions[userRole];
    if (!role) return;
    
    // Update page title or user info based on role
    const roleDisplay = document.getElementById('userRoleDisplay');
    if (roleDisplay) {
        roleDisplay.textContent = role.name;
    }
    
    // Disable/enable action buttons based on permissions
    document.querySelectorAll('[data-permission]').forEach(element => {
        const permission = element.getAttribute('data-permission');
        const action = element.getAttribute('data-action') || 'read';
        const module = element.getAttribute('data-module');
        
        if (module && !hasModuleAccess(userRole, module, action)) {
            element.disabled = true;
            element.style.opacity = '0.5';
            element.title = 'No permission for this action';
        }
    });
}

// Export for use in other files
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        rolePermissions,
        navigationConfig,
        hasPermission,
        hasModuleAccess,
        canAccessNavigation,
        checkPageAccess,
        initializeRBAC
    };
}
