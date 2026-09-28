// RBAC System Test Suite
// Test functionality of RBAC permissions and navigation

// Test function to verify RBAC system
function testRBACSystem() {
    console.log('🧪 Testing RBAC System...');
    
    // Test 1: Check if all roles are defined
    const requiredRoles = [
        'mkurugenzi_mtendaji', 'mkuu_wa_kituo', 'mkuu_wa_idara', 'mhasibu', 
        'afisa_rasilimali_watu', 'afisa_miradi', 'afisa_maendeleo_ya_jamii',
        'afisa_kilimo_mifugo', 'afisa_elimu_mkufunzi', 'afisa_ufuatiliaji_tathmini',
        'afisa_takwimu', 'afisa_huduma_za_jamii', 'afisa_maji', 'mratibu_wa_vikundi',
        'afisa_uwanja', 'msaidizi_wa_uwanja', 'katibu_tawala', 'katibu_mtendaji',
        'meneja_programu', 'kocha_mafunzo', 'meneja_mawasiliano', 'afisa_misaa',
        'meneja_misaa', 'afisa_matukio', 'meneja_matukio', 'afisa_it', 'meneja_it',
        'afisa_usalama', 'meneja_usalama', 'afisa_matangazo', 'meneja_matangazo',
        'afisa_ugavi', 'meneja_ugavi', 'afisa_uchukuzi', 'meneja_uchukuzi',
        'afisa_upatikanaji_wa_vifaa', 'meneja_upatikanaji_wa_vifaa', 'afisa_mazingira',
        'meneja_mazingira', 'afisa_afya', 'meneja_afya', 'afisa_maji_na_usafi',
        'meneja_maji_na_usafi'
    ];
    
    let missingRoles = [];
    requiredRoles.forEach(role => {
        if (!rolePermissions[role]) {
            missingRoles.push(role);
        }
    });
    
    if (missingRoles.length > 0) {
        console.error('❌ Missing roles:', missingRoles);
        return false;
    } else {
        console.log('✅ All roles are defined');
    }
    
    // Test 2: Check role structure
    let invalidRoles = [];
    Object.keys(rolePermissions).forEach(role => {
        const roleData = rolePermissions[role];
        if (!roleData.level || !roleData.name || !roleData.permissions || !roleData.modules || !roleData.navigation) {
            invalidRoles.push(role);
        }
    });
    
    if (invalidRoles.length > 0) {
        console.error('❌ Invalid role structure:', invalidRoles);
        return false;
    } else {
        console.log('✅ All roles have valid structure');
    }
    
    // Test 3: Check navigation configuration
    const requiredNavItems = ['users', 'departments', 'planning', 'beneficiaries', 'finance', 'reports', 'system'];
    let missingNavItems = [];
    requiredNavItems.forEach(nav => {
        if (!navigationConfig[nav]) {
            missingNavItems.push(nav);
        }
    });
    
    if (missingNavItems.length > 0) {
        console.error('❌ Missing navigation items:', missingNavItems);
        return false;
    } else {
        console.log('✅ All navigation items are defined');
    }
    
    // Test 4: Test permission functions
    const testCases = [
        { role: 'mkurugenzi_mtendaji', permission: 'all', expected: true },
        { role: 'mhasibu', permission: 'finance', expected: true },
        { role: 'mhasibu', permission: 'hr', expected: false },
        { role: 'afisa_rasilimali_watu', permission: 'hr', expected: true },
        { role: 'afisa_miradi', permission: 'projects', expected: true },
        { role: 'afisa_miradi', permission: 'finance', expected: false }
    ];
    
    let failedTests = [];
    testCases.forEach(test => {
        const result = hasPermission(test.role, test.permission);
        if (result !== test.expected) {
            failedTests.push({
                role: test.role,
                permission: test.permission,
                expected: test.expected,
                actual: result
            });
        }
    });
    
    if (failedTests.length > 0) {
        console.error('❌ Permission tests failed:', failedTests);
        return false;
    } else {
        console.log('✅ Permission tests passed');
    }
    
    // Test 5: Test module access
    const moduleTests = [
        { role: 'mkurugenzi_mtendaji', module: 'users', action: 'delete', expected: true },
        { role: 'mhasibu', module: 'finance', action: 'create', expected: true },
        { role: 'mhasibu', module: 'users', action: 'delete', expected: false },
        { role: 'afisa_huduma_za_jamii', module: 'beneficiaries', action: 'create', expected: true },
        { role: 'afisa_huduma_za_jamii', module: 'system', action: 'delete', expected: false }
    ];
    
    let failedModuleTests = [];
    moduleTests.forEach(test => {
        const result = hasModuleAccess(test.role, test.module, test.action);
        if (result !== test.expected) {
            failedModuleTests.push({
                role: test.role,
                module: test.module,
                action: test.action,
                expected: test.expected,
                actual: result
            });
        }
    });
    
    if (failedModuleTests.length > 0) {
        console.error('❌ Module access tests failed:', failedModuleTests);
        return false;
    } else {
        console.log('✅ Module access tests passed');
    }
    
    // Test 6: Test navigation access
    const navTests = [
        { role: 'mkurugenzi_mtendaji', navId: 'system', expected: true },
        { role: 'mhasibu', navId: 'finance', expected: true },
        { role: 'mhasibu', navId: 'system', expected: false },
        { role: 'afisa_huduma_za_jamii', navId: 'beneficiaries', expected: true },
        { role: 'afisa_huduma_za_jamii', navId: 'system', expected: false }
    ];
    
    let failedNavTests = [];
    navTests.forEach(test => {
        const result = canAccessNavigation(test.role, test.navId);
        if (result !== test.expected) {
            failedNavTests.push({
                role: test.role,
                navId: test.navId,
                expected: test.expected,
                actual: result
            });
        }
    });
    
    if (failedNavTests.length > 0) {
        console.error('❌ Navigation access tests failed:', failedNavTests);
        return false;
    } else {
        console.log('✅ Navigation access tests passed');
    }
    
    console.log('🎉 All RBAC tests passed! System is working properly.');
    return true;
}

// Test navigation filtering
function testNavigationFiltering() {
    console.log('🧪 Testing Navigation Filtering...');
    
    // Test different roles and their accessible navigation
    const testRoles = [
        'mkurugenzi_mtendaji',
        'mhasibu',
        'afisa_rasilimali_watu',
        'afisa_miradi',
        'afisa_huduma_za_jamii',
        'katibu_tawala'
    ];
    
    testRoles.forEach(role => {
        const accessibleNav = getAccessibleNavigation(role);
        const filteredNav = filterNavigationByRole(role);
        
        console.log(`📋 Role: ${role} (${rolePermissions[role].name})`);
        console.log(`   Accessible Navigation:`, accessibleNav);
        console.log(`   Filtered Navigation Items:`, Object.keys(filteredNav));
        
        // Check if navigation items match role permissions
        accessibleNav.forEach(navId => {
            if (!filteredNav[navId]) {
                console.error(`❌ Navigation item ${navId} should be accessible for role ${role}`);
            }
        });
    });
    
    console.log('✅ Navigation filtering test completed');
}

// Test role hierarchy
function testRoleHierarchy() {
    console.log('🧪 Testing Role Hierarchy...');
    
    // Test that higher levels have more or equal access
    const levels = {};
    Object.keys(rolePermissions).forEach(role => {
        const level = rolePermissions[role].level;
        if (!levels[level]) {
            levels[level] = [];
        }
        levels[level].push(role);
    });
    
    console.log('📊 Role Levels:');
    Object.keys(levels).sort((a, b) => a - b).forEach(level => {
        console.log(`   Level ${level}:`, levels[level]);
    });
    
    // Test hierarchy logic
    for (let i = 1; i <= 5; i++) {
        const currentLevel = i;
        const rolesAtLevel = levels[currentLevel] || [];
        
        rolesAtLevel.forEach(role => {
            const roleData = rolePermissions[role];
            const accessibleNav = getAccessibleNavigation(role);
            
            // Check if level 1 has all navigation
            if (currentLevel === 1) {
                const allNavItems = Object.keys(navigationConfig);
                const hasAllNav = allNavItems.every(nav => accessibleNav.includes(nav));
                if (!hasAllNav) {
                    console.error(`❌ Level 1 role ${role} should have access to all navigation`);
                }
            }
        });
    }
    
    console.log('✅ Role hierarchy test completed');
}

// Run all tests
function runAllRBACTests() {
    console.log('🚀 Starting RBAC System Tests...');
    console.log('=====================================');
    
    const testResults = {
        basicRBAC: testRBACSystem(),
        navigationFiltering: testNavigationFiltering(),
        roleHierarchy: testRoleHierarchy()
    };
    
    console.log('=====================================');
    console.log('📊 Test Results Summary:');
    Object.keys(testResults).forEach(test => {
        console.log(`   ${test}: ${testResults[test] ? '✅ PASSED' : '❌ FAILED'}`);
    });
    
    const allPassed = Object.values(testResults).every(result => result);
    console.log(`🎯 Overall Result: ${allPassed ? '✅ ALL TESTS PASSED' : '❌ SOME TESTS FAILED'}`);
    
    return allPassed;
}

// Export test functions
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        testRBACSystem,
        testNavigationFiltering,
        testRoleHierarchy,
        runAllRBACTests
    };
}

// Auto-run tests when script loads (for debugging)
if (typeof window !== 'undefined') {
    // Uncomment to run tests automatically
    // runAllRBACTests();
}
