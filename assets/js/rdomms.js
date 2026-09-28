/**
 * RDOMMS - Rural Development Organization Monitoring and Management System
 * Main JavaScript Framework
 */

// Global variables
let currentUser = null;
let isOnline = navigator.onLine;
let db = null;

// Initialize application
document.addEventListener('DOMContentLoaded', function() {
    initializeApp();
});

function initializeApp() {
    // Initialize IndexedDB for offline support
    initializeIndexedDB();
    
    // Check online status
    updateOnlineStatus();
    
    // Load current user data
    loadCurrentUser();
    
    // Initialize sidebar
    initializeSidebar();
    
    // Initialize modals
    initializeModals();
    
    // Initialize tabs
    initializeTabs();
    
    // Initialize forms
    initializeForms();
    
    // Setup event listeners
    setupEventListeners();
    
    // Check for notifications
    checkNotifications();
    
    // Auto-sync data when online
    setupAutoSync();
}

// ===== IndexedDB for Offline Support =====
function initializeIndexedDB() {
    if (!window.indexedDB) {
        console.warn('IndexedDB not supported');
        return;
    }
    
    const request = indexedDB.open('RDOMMS', 1);
    
    request.onerror = function(event) {
        console.error('Database error:', event.target.error);
    };
    
    request.onsuccess = function(event) {
        db = event.target.result;
        console.log('Database initialized successfully');
    };
    
    request.onupgradeneeded = function(event) {
        db = event.target.result;
        
        // Create object stores for offline data
        if (!db.objectStoreNames.contains('projects')) {
            const projectStore = db.createObjectStore('projects', { keyPath: 'id' });
            projectStore.createIndex('status', 'status', { unique: false });
        }
        
        if (!db.objectStoreNames.contains('tasks')) {
            const taskStore = db.createObjectStore('tasks', { keyPath: 'id' });
            taskStore.createIndex('assigned_to', 'assigned_to', { unique: false });
        }
        
        if (!db.objectStoreNames.contains('activities')) {
            const activityStore = db.createObjectStore('activities', { keyPath: 'id' });
            activityStore.createIndex('project_id', 'project_id', { unique: false });
        }
        
        if (!db.objectStoreNames.contains('sync_queue')) {
            const syncStore = db.createObjectStore('sync_queue', { keyPath: 'id', autoIncrement: true });
            syncStore.createIndex('timestamp', 'timestamp', { unique: false });
        }
    };
}

// ===== Online/Offline Status =====
function updateOnlineStatus() {
    isOnline = navigator.onLine;
    const indicator = document.querySelector('.offline-indicator');
    
    if (isOnline) {
        document.body.classList.remove('offline');
        if (indicator) {
            indicator.classList.remove('active');
        }
        syncData();
    } else {
        document.body.classList.add('offline');
        if (indicator) {
            indicator.classList.add('active');
        }
    }
}

window.addEventListener('online', updateOnlineStatus);
window.addEventListener('offline', updateOnlineStatus);

// ===== Sidebar Functionality =====
function initializeSidebar() {
    const toggleBtn = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    const mainContent = document.querySelector('.main-content');
    
    if (toggleBtn && sidebar && mainContent) {
        toggleBtn.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            
            // Save preference
            localStorage.setItem('sidebar_collapsed', sidebar.classList.contains('collapsed'));
        });
        
        // Restore preference
        const isCollapsed = localStorage.getItem('sidebar_collapsed') === 'true';
        if (isCollapsed) {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
        }
    }
    
    // Mobile sidebar toggle
    if (window.innerWidth <= 768) {
        sidebar.classList.remove('collapsed');
        mainContent.classList.remove('expanded');
    }
}

// ===== Modal Functionality =====
function initializeModals() {
    const modalTriggers = document.querySelectorAll('[data-modal]');
    const modals = document.querySelectorAll('.modal');
    const closeButtons = document.querySelectorAll('.modal-close');
    
    modalTriggers.forEach(trigger => {
        trigger.addEventListener('click', function(e) {
            e.preventDefault();
            const modalId = this.getAttribute('data-modal');
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        });
    });
    
    closeButtons.forEach(button => {
        button.addEventListener('click', function() {
            const modal = this.closest('.modal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    });
    
    modals.forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    });
}

// ===== Tab Functionality =====
function initializeTabs() {
    const tabButtons = document.querySelectorAll('.tab-btn');
    const tabContents = document.querySelectorAll('.tab-content');
    
    tabButtons.forEach(button => {
        button.addEventListener('click', function() {
            const targetTab = this.getAttribute('data-tab');
            
            // Remove active class from all buttons and contents
            tabButtons.forEach(btn => btn.classList.remove('active'));
            tabContents.forEach(content => content.classList.remove('active'));
            
            // Add active class to clicked button and corresponding content
            this.classList.add('active');
            const targetContent = document.getElementById(targetTab);
            if (targetContent) {
                targetContent.classList.add('active');
            }
        });
    });
}

// ===== Form Functionality =====
function initializeForms() {
    const forms = document.querySelectorAll('form[data-ajax]');
    
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            submitForm(this);
        });
    });
}

function submitForm(form) {
    const formData = new FormData(form);
    const submitBtn = form.querySelector('[type="submit"]');
    const originalText = submitBtn.textContent;
    
    // Show loading state
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner"></span> Processing...';
    
    // Convert FormData to JSON
    const data = {};
    formData.forEach((value, key) => {
        data[key] = value;
    });
    
    // Determine if online or offline
    if (isOnline) {
        // Submit to server
        fetch(form.action, {
            method: form.method || 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(result => {
            handleFormResponse(form, result, submitBtn, originalText);
        })
        .catch(error => {
            console.error('Form submission error:', error);
            showMessage('Submission failed. Please try again.', 'error');
            resetFormButton(submitBtn, originalText);
        });
    } else {
        showMessage('You are offline. Please reconnect and try again.', 'warning');
        resetFormButton(submitBtn, originalText);
    }
}

function handleFormResponse(form, result, submitBtn, originalText) {
    resetFormButton(submitBtn, originalText);
    
    if (result.success) {
        showMessage(result.message || 'Operation completed successfully', 'success');
        
        // Reset form if specified
        if (form.dataset.reset === 'true') {
            form.reset();
        }
        
        // Close modal if in modal
        const modal = form.closest('.modal');
        if (modal) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
        
        // Refresh data if needed
        if (form.dataset.refresh) {
            refreshData(form.dataset.refresh);
        }
    } else {
        showMessage(result.message || 'Operation failed', 'error');
    }
}

function resetFormButton(button, originalText) {
    button.disabled = false;
    button.textContent = originalText;
}

// ===== Data Management =====
function loadCurrentUser() {
    // Try to get from session first, then from localStorage
    const userElement = document.getElementById('current-user-data');
    if (userElement) {
        try {
            currentUser = JSON.parse(userElement.textContent);
        } catch (e) {
            console.error('Error parsing user data:', e);
        }
    }
}

function saveFormDataOffline(url, data) {
    if (!db) return;
    
    const transaction = db.transaction(['sync_queue'], 'readwrite');
    const store = transaction.objectStore('sync_queue');
    
    const syncItem = {
        url: url,
        data: data,
        method: 'POST',
        timestamp: Date.now()
    };
    
    store.add(syncItem);
}

function syncData() {
    if (!db || !isOnline) return;
    
    const transaction = db.transaction(['sync_queue'], 'readwrite');
    const store = transaction.objectStore('sync_queue');
    const request = store.getAll();
    
    request.onsuccess = function(event) {
        const items = event.target.result;
        
        if (items.length === 0) return;
        
        items.forEach(item => {
            fetch(item.url, {
                method: item.method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(item.data)
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    // Remove from sync queue
                    const deleteRequest = store.delete(item.id);
                    deleteRequest.onsuccess = function() {
                        console.log('Synced item removed from queue');
                    };
                }
            })
            .catch(error => {
                console.error('Sync error:', error);
            });
        });
    };
}

function setupAutoSync() {
    // Sync every 30 seconds when online
    setInterval(() => {
        if (isOnline) {
            syncData();
        }
    }, 30000);
}

// ===== API Functions =====
async function apiRequest(url, options = {}) {
    const defaultOptions = {
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    };
    
    const finalOptions = { ...defaultOptions, ...options };
    
    try {
        const response = await fetch(url, finalOptions);
        const data = await response.json();
        return data;
    } catch (error) {
        console.error('API request error:', error);
        throw error;
    }
}

async function getDashboardData() {
    try {
        const data = await apiRequest('/api/dashboard');
        return data;
    } catch (error) {
        // Try to get from IndexedDB
        return getOfflineData('dashboard');
    }
}

async function getProjects(filters = {}) {
    const params = new URLSearchParams(filters);
    try {
        const data = await apiRequest(`/api/projects?${params}`);
        return data;
    } catch (error) {
        return getOfflineData('projects');
    }
}

// ===== UI Helper Functions =====
function showMessage(message, type = 'info') {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type}`;
    alertDiv.textContent = message;
    
    const container = document.querySelector('.content') || document.body;
    container.insertBefore(alertDiv, container.firstChild);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        alertDiv.remove();
    }, 5000);
}

function confirmAction(message, callback) {
    if (confirm(message)) {
        callback();
    }
}

function formatDate(date, format = 'short') {
    const d = new Date(date);
    
    if (format === 'short') {
        return d.toLocaleDateString();
    } else if (format === 'long') {
        return d.toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'long', 
            day: 'numeric' 
        });
    } else if (format === 'time') {
        return d.toLocaleTimeString();
    }
    
    return d.toLocaleDateString();
}

function formatCurrency(amount, currency = 'TSH') {
    if (typeof window.formatRdommsCurrency === 'function') {
        return window.formatRdommsCurrency(amount, currency);
    }
    return `${currency} ${Number(amount || 0).toLocaleString('en-US')}`;
}

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// ===== Event Listeners =====
function setupEventListeners() {
    // Search functionality
    const searchInput = document.querySelector('.search-bar input');
    if (searchInput) {
        searchInput.addEventListener('input', debounce(handleSearch, 300));
    }
    
    // Notification bell
    const notificationBell = document.querySelector('.notification-bell');
    if (notificationBell) {
        notificationBell.addEventListener('click', toggleNotifications);
    }
    
    // User menu
    const userMenu = document.querySelector('.user-menu');
    if (userMenu) {
        userMenu.addEventListener('click', toggleUserMenu);
    }
    
    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.notification-dropdown') && !e.target.closest('.notification-bell')) {
            closeNotifications();
        }
        if (!e.target.closest('.user-dropdown') && !e.target.closest('.user-menu')) {
            closeUserMenu();
        }
    });
}

function handleSearch(e) {
    const query = e.target.value.toLowerCase();
    const searchableElements = document.querySelectorAll('[data-searchable]');
    
    searchableElements.forEach(element => {
        const text = element.textContent.toLowerCase();
        const parent = element.closest('tr, .card, .list-item');
        
        if (parent) {
            parent.style.display = text.includes(query) ? '' : 'none';
        }
    });
}

function toggleNotifications(e) {
    e.stopPropagation();
    const dropdown = document.querySelector('.notification-dropdown');
    if (dropdown) {
        dropdown.classList.toggle('active');
    }
}

function closeNotifications() {
    const dropdown = document.querySelector('.notification-dropdown');
    if (dropdown) {
        dropdown.classList.remove('active');
    }
}

function toggleUserMenu(e) {
    e.stopPropagation();
    const dropdown = document.querySelector('.user-dropdown');
    if (dropdown) {
        dropdown.classList.toggle('active');
    }
}

function closeUserMenu() {
    const dropdown = document.querySelector('.user-dropdown');
    if (dropdown) {
        dropdown.classList.remove('active');
    }
}

function checkNotifications() {
    // Check for new notifications
    fetch('/api/notifications/unread')
        .then(response => response.json())
        .then(data => {
            if (data.count > 0) {
                const badge = document.querySelector('.notification-badge');
                if (badge) {
                    badge.textContent = data.count;
                    badge.style.display = 'flex';
                }
            }
        })
        .catch(error => {
            console.error('Notification check error:', error);
        });
}

// ===== Chart Helper Functions =====
function createChart(elementId, type, data, options = {}) {
    const canvas = document.getElementById(elementId);
    if (!canvas) return null;
    
    const ctx = canvas.getContext('2d');
    
    // Simple chart implementation (can be replaced with Chart.js)
    const defaultOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'top',
            }
        }
    };
    
    const finalOptions = { ...defaultOptions, ...options };
    
    // This is a placeholder - implement actual chart library
    console.log('Chart created:', { type, data, options });
    return { ctx, data, options };
}

// ===== Export Functions =====
function exportToCSV(data, filename) {
    const csv = convertToCSV(data);
    downloadFile(csv, filename, 'text/csv');
}

function exportToPDF(data, filename) {
    // This would use a PDF library like jsPDF
    console.log('PDF export:', filename, data);
}

function convertToCSV(data) {
    if (!data || data.length === 0) return '';
    
    const headers = Object.keys(data[0]);
    const csvContent = [
        headers.join(','),
        ...data.map(row => headers.map(header => `"${row[header] || ''}"`).join(','))
    ].join('\n');
    
    return csvContent;
}

function downloadFile(content, filename, contentType) {
    const blob = new Blob([content], { type: contentType });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

// ===== Utility Functions =====
function generateId() {
    return Date.now().toString(36) + Math.random().toString(36).substr(2);
}

function validateForm(form) {
    const inputs = form.querySelectorAll('input[required], select[required], textarea[required]');
    let isValid = true;
    
    inputs.forEach(input => {
        if (!input.value.trim()) {
            input.classList.add('error');
            isValid = false;
        } else {
            input.classList.remove('error');
        }
    });
    
    return isValid;
}

function sanitizeInput(input) {
    const div = document.createElement('div');
    div.textContent = input;
    return div.innerHTML;
}

// ===== Module Exports =====
window.RDOMMS = {
    apiRequest,
    getDashboardData,
    getProjects,
    showMessage,
    confirmAction,
    formatDate,
    formatCurrency,
    exportToCSV,
    exportToPDF,
    createChart,
    db,
    isOnline,
    currentUser
};
