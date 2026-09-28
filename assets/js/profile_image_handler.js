/**
 * Profile Image Handler for RDOMMS
 * Handles uploading, storing, and displaying user profile images
 */

class ProfileImageHandler {
    constructor() {
        this.maxFileSize = 5 * 1024 * 1024; // 5MB
        this.allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        this.storageKey = 'userProfileImages';
        this.init();
    }

    init() {
        this.setupEventListeners();
        this.loadStoredImages();
    }

    setupEventListeners() {
        // Listen for file input changes
        document.addEventListener('change', (e) => {
            if (e.target.id === 'profileImageInput') {
                this.handleImageUpload(e.target.files[0]);
            }
        });

        // Listen for profile image clicks
        document.addEventListener('click', (e) => {
            if (e.target.closest('.profile-avatar') || e.target.closest('.profile-img-container')) {
                this.showImageUploadDialog();
            }
        });
    }

    handleImageUpload(file) {
        if (!file) return;

        // Validate file type
        if (!this.allowedTypes.includes(file.type)) {
            this.showNotification('Unsupported image type. Please use JPG, PNG, GIF, or WebP.', 'error');
            return;
        }

        // Validate file size
        if (file.size > this.maxFileSize) {
            this.showNotification('The image is too large. Please use an image under 5MB.', 'error');
            return;
        }

        // Read and process the image
        const reader = new FileReader();
        reader.onload = (e) => {
            const imageData = e.target.result;
            this.saveProfileImage(imageData);
            this.updateProfileDisplay(imageData);
            this.showNotification('Profile photo updated successfully!', 'success');
        };
        reader.readAsDataURL(file);
    }

    saveProfileImage(imageData) {
        const currentUser = JSON.parse(localStorage.getItem('currentUser') || '{}');
        if (!currentUser.id) return;

        // Get existing stored images
        const storedImages = JSON.parse(localStorage.getItem(this.storageKey) || '{}');
        
        // Save image for current user
        storedImages[currentUser.id] = {
            imageData: imageData,
            uploadedAt: new Date().toISOString(),
            userId: currentUser.id
        };

        // Update to localStorage
        localStorage.setItem(this.storageKey, JSON.stringify(storedImages));

        // Update current user's profile image
        currentUser.profileImage = imageData;
        currentUser.profile_picture = imageData;
        localStorage.setItem('currentUser', JSON.stringify(currentUser));

        // Update user in users array
        const users = JSON.parse(localStorage.getItem('users') || '[]');
        const userIndex = users.findIndex(u => u.id === currentUser.id);
        if (userIndex !== -1) {
            users[userIndex].profileImage = imageData;
            users[userIndex].profile_picture = imageData;
            localStorage.setItem('users', JSON.stringify(users));
        }

        // Log activity
        this.logProfileImageUpdate();
    }

    loadStoredImages() {
        const currentUser = JSON.parse(localStorage.getItem('currentUser') || '{}');
        if (!currentUser.id) return;

        const storedImages = JSON.parse(localStorage.getItem(this.storageKey) || '{}');
        const userImage = storedImages[currentUser.id];

        if (userImage && userImage.imageData) {
            this.updateProfileDisplay(userImage.imageData);
        }
    }

    updateProfileDisplay(imageData) {
        // Update all profile image displays on the page
        const profileImages = document.querySelectorAll('.profile-img, #userProfileImage, #userAvatar, #profileAvatar');
        profileImages.forEach(img => {
            if (img.tagName === 'IMG') {
                img.src = imageData;
                img.style.display = 'block';
            } else {
                img.innerHTML = `<img src="${imageData}" alt="Profile" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">`;
                img.style.background = 'transparent';
            }
        });

        // Update profile avatars in activity tables
        const profileAvatars = document.querySelectorAll('.user-avatar');
        profileAvatars.forEach(avatar => {
            if (!avatar.querySelector('img')) {
                const img = document.createElement('img');
                img.src = imageData;
                img.alt = 'Profile';
                img.className = 'profile-img';
                img.style.cssText = 'width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid #f8f9fa;';
                avatar.innerHTML = '';
                avatar.appendChild(img);
            }
        });

        // Update profile sections that might exist
        const profileSections = document.querySelectorAll('.profile-avatar');
        profileSections.forEach(section => {
            const existingImg = section.querySelector('.profile-img');
            if (existingImg) {
                existingImg.src = imageData;
            } else {
                const img = document.createElement('img');
                img.src = imageData;
                img.alt = 'Profile';
                img.className = 'profile-img';
                img.id = 'userProfileImage';
                section.innerHTML = '';
                section.appendChild(img);
            }
        });
    }

    showImageUploadDialog() {
        // Create modal for image upload
        const modal = document.createElement('div');
        modal.className = 'profile-image-modal';
        modal.innerHTML = `
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Update Profile Photo</h3>
                    <button class="close-btn" onclick="this.closest('.profile-image-modal').remove()">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="upload-area" id="uploadArea">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <p>Click to choose an image or drag and drop it here</p>
                        <input type="file" id="profileImageInput" accept="image/*" style="display: none;">
                        <button class="btn btn-primary" onclick="document.getElementById('profileImageInput').click()">
                            <i class="fas fa-upload"></i> Choose Image
                        </button>
                    </div>
                    <div class="image-preview" id="imagePreview" style="display: none;">
                        <img id="previewImg" src="" alt="Preview">
                        <div class="preview-actions">
                            <button class="btn btn-success" onclick="profileImageHandler.confirmImageUpload()">
                                <i class="fas fa-check"></i> Confirm
                            </button>
                            <button class="btn btn-danger" onclick="profileImageHandler.cancelImageUpload()">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // Add styles
        modal.innerHTML += `
            <style>
                .profile-image-modal {
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0, 0, 0, 0.5);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    z-index: 10000;
                }

                .modal-content {
                    background: white;
                    border-radius: 12px;
                    padding: 0;
                    max-width: 500px;
                    width: 90%;
                    max-height: 90vh;
                    overflow: hidden;
                }

                .modal-header {
                    padding: 20px;
                    border-bottom: 1px solid #eee;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }

                .modal-header h3 {
                    margin: 0;
                    color: #2c5530;
                }

                .close-btn {
                    background: none;
                    border: none;
                    font-size: 24px;
                    cursor: pointer;
                    color: #999;
                }

                .modal-body {
                    padding: 20px;
                }

                .upload-area {
                    border: 2px dashed #ddd;
                    border-radius: 8px;
                    padding: 40px;
                    text-align: center;
                    transition: border-color 0.3s;
                }

                .upload-area:hover {
                    border-color: #2c5530;
                }

                .upload-area i {
                    font-size: 48px;
                    color: #2c5530;
                    margin-bottom: 15px;
                }

                .image-preview {
                    text-align: center;
                }

                .image-preview img {
                    max-width: 100%;
                    max-height: 300px;
                    border-radius: 8px;
                    margin-bottom: 20px;
                }

                .preview-actions {
                    display: flex;
                    gap: 10px;
                    justify-content: center;
                }

                .btn {
                    padding: 10px 20px;
                    border: none;
                    border-radius: 6px;
                    cursor: pointer;
                    font-weight: 500;
                    transition: all 0.3s;
                }

                .btn-primary {
                    background: #2c5530;
                    color: white;
                }

                .btn-success {
                    background: #27ae60;
                    color: white;
                }

                .btn-danger {
                    background: #e74c3c;
                    color: white;
                }

                .btn:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
                }
            </style>
        `;

        document.body.appendChild(modal);

        // Setup drag and drop
        const uploadArea = modal.querySelector('#uploadArea');
        const fileInput = modal.querySelector('#profileImageInput');

        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.style.borderColor = '#2c5530';
        });

        uploadArea.addEventListener('dragleave', () => {
            uploadArea.style.borderColor = '#ddd';
        });

        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.style.borderColor = '#ddd';
            const file = e.dataTransfer.files[0];
            if (file) {
                this.previewImage(file);
            }
        });

        fileInput.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) {
                this.previewImage(file);
            }
        });
    }

    previewImage(file) {
        if (!this.allowedTypes.includes(file.type)) {
            this.showNotification('Unsupported image type.', 'error');
            return;
        }

        if (file.size > this.maxFileSize) {
            this.showNotification('The image is too large.', 'error');
            return;
        }

        const reader = new FileReader();
        reader.onload = (e) => {
            const imageData = e.target.result;
            const previewImg = document.querySelector('#previewImg');
            previewImg.src = imageData;
            
            document.querySelector('#uploadArea').style.display = 'none';
            document.querySelector('#imagePreview').style.display = 'block';
            
            // Store the image data temporarily
            this.tempImageData = imageData;
        };
        reader.readAsDataURL(file);
    }

    confirmImageUpload() {
        if (this.tempImageData) {
            this.saveProfileImage(this.tempImageData);
            this.updateProfileDisplay(this.tempImageData);
            this.showNotification('Profile photo updated successfully!', 'success');
            document.querySelector('.profile-image-modal').remove();
        }
    }

    cancelImageUpload() {
        this.tempImageData = null;
        document.querySelector('.profile-image-modal').remove();
    }

    logProfileImageUpdate() {
        // Log the profile image update activity
        if (window.activityTracker) {
            window.activityTracker.logActivity('update', 'Profile photo updated');
        }
    }

    showNotification(message, type = 'info') {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.innerHTML = `
            <div class="notification-content">
                <p>${message}</p>
            </div>
            <button class="notification-close" onclick="this.parentElement.remove()">×</button>
        `;
        
        // Add styles if not already present
        if (!document.querySelector('#notification-styles')) {
            const style = document.createElement('style');
            style.id = 'notification-styles';
            style.textContent = `
                .notification {
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    background: white;
                    border-radius: 8px;
                    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                    z-index: 9999;
                    min-width: 300px;
                    max-width: 400px;
                    animation: slideIn 0.3s ease-out;
                }

                .notification-success {
                    border-left: 4px solid #27ae60;
                }

                .notification-error {
                    border-left: 4px solid #e74c3c;
                }

                .notification-info {
                    border-left: 4px solid #3498db;
                }

                .notification-content {
                    padding: 15px;
                }

                .notification-close {
                    position: absolute;
                    top: 8px;
                    right: 8px;
                    background: none;
                    border: none;
                    font-size: 18px;
                    cursor: pointer;
                    color: #999;
                }

                @keyframes slideIn {
                    from {
                        transform: translateX(100%);
                        opacity: 0;
                    }
                    to {
                        transform: translateX(0);
                        opacity: 1;
                    }
                }
            `;
            document.head.appendChild(style);
        }
        
        document.body.appendChild(notification);
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            notification.remove();
        }, 5000);
    }

    // Get profile image for a specific user
    getProfileImage(userId) {
        const storedImages = JSON.parse(localStorage.getItem(this.storageKey) || '{}');
        return storedImages[userId] ? storedImages[userId].imageData : null;
    }

    // Remove profile image
    removeProfileImage() {
        const currentUser = JSON.parse(localStorage.getItem('currentUser') || '{}');
        if (!currentUser.id) return;

        const storedImages = JSON.parse(localStorage.getItem(this.storageKey) || '{}');
        delete storedImages[currentUser.id];
        localStorage.setItem(this.storageKey, JSON.stringify(storedImages));

        // Update current user
        delete currentUser.profileImage;
        localStorage.setItem('currentUser', JSON.stringify(currentUser));

        // Update users array
        const users = JSON.parse(localStorage.getItem('users') || '[]');
        const userIndex = users.findIndex(u => u.id === currentUser.id);
        if (userIndex !== -1) {
            delete users[userIndex].profileImage;
            localStorage.setItem('users', JSON.stringify(users));
        }

        this.showNotification('Profile photo removed', 'info');
    }
}

// Initialize the profile image handler
const profileImageHandler = new ProfileImageHandler();

// Make it globally available
window.profileImageHandler = profileImageHandler;
