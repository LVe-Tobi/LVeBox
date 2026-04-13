// Variables globales
let currentUserId = null;
let currentUsername = null;
let pendingAttachments = [];
let messagePolling = null;

// Initialisation
document.addEventListener('DOMContentLoaded', () => {
    checkSession();
    setupEventListeners();
    startMessagePolling();
});

function setupEventListeners() {
    // Recherche d'utilisateurs
    const searchInput = document.getElementById('searchUsers');
    if (searchInput) {
        let debounceTimer;
        searchInput.addEventListener('input', (e) => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                searchUsers(e.target.value);
            }, 300);
        });
    }
    
    // Fermeture de la modale
    const modal = document.getElementById('fileModal');
    const closeBtn = document.querySelector('.close');
    if (closeBtn) {
        closeBtn.onclick = () => modal.style.display = 'none';
    }
    window.onclick = (event) => {
        if (event.target === modal) modal.style.display = 'none';
    };
}

function startMessagePolling() {
    if (messagePolling) clearInterval(messagePolling);
    messagePolling = setInterval(() => {
        if (window.location.pathname.includes('dashboard.html')) {
            updateUnreadCount();
        }
    }, 5000);
}

function updateUnreadCount() {
    fetch('backend.php?action=get_unread_count')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data) {
                const badge = document.getElementById('unreadCount');
                if (badge) {
                    const count = data.data.count;
                    badge.textContent = count;
                    badge.style.display = count > 0 ? 'inline-block' : 'none';
                }
            }
        });
}

function checkSession() {
    fetch('backend.php?action=check_session')
        .then(response => response.json())
        .then(data => {
            if (!data.success && !window.location.pathname.includes('login.html') && 
                !window.location.pathname.includes('register.html') && 
                !window.location.pathname.includes('index.html')) {
                showToast('Session expirée', 'error');
                setTimeout(() => window.location.href = 'login.html', 1500);
            } else if (data.success && window.location.pathname.includes('login.html')) {
                window.location.href = 'dashboard.html';
            } else if (data.success && window.location.pathname.includes('dashboard.html')) {
                currentUserId = data.data.user_id;
                currentUsername = data.data.username;
                document.getElementById('usernameDisplay').textContent = data.data.username;
                loadMessages('inbox');
            }
        });
}

function searchUsers(query) {
    if (query.length < 2) {
        document.getElementById('searchResults').style.display = 'none';
        return;
    }
    
    fetch(`backend.php?action=search_users&q=${encodeURIComponent(query)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data.length > 0) {
                const results = document.getElementById('searchResults');
                results.innerHTML = data.data.map(user => `
                    <div class="search-result-item" onclick="startConversationWith('${user.username}')">
                        <div class="result-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="result-info">
                            <strong>${user.username}</strong>
                            <small>${user.email}</small>
                        </div>
                        <span class="status-dot ${user.status}"></span>
                    </div>
                `).join('');
                results.style.display = 'block';
            } else {
                document.getElementById('searchResults').style.display = 'none';
            }
        });
}

function startConversationWith(username) {
    document.getElementById('searchUsers').value = '';
    document.getElementById('searchResults').style.display = 'none';
    showNewMessageForm(username);
}

function showConversations() {
    const contentArea = document.getElementById('contentArea');
    contentArea.innerHTML = '<div class="loading-state"><div class="spinner"></div><p>Chargement...</p></div>';
    
    fetch('backend.php?action=get_conversations')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data.length > 0) {
                let html = '<div class="conversations-list">';
                data.data.forEach(conv => {
                    const lastMessageTime = new Date(conv.last_message_time).toLocaleString();
                    html += `
                        <div class="conversation-item" onclick="showConversationMessages(${conv.other_user_id}, '${conv.other_username}')">
                            <div class="conv-avatar">
                                <i class="fas fa-user"></i>
                                <span class="status-dot ${conv.other_status}"></span>
                            </div>
                            <div class="conv-info">
                                <div class="conv-header">
                                    <strong>${conv.other_username}</strong>
                                    <small>${lastMessageTime}</small>
                                </div>
                                <div class="conv-last-message">${conv.last_message || 'Aucun message'}</div>
                            </div>
                            ${conv.unread_count > 0 ? `<span class="unread-badge">${conv.unread_count}</span>` : ''}
                        </div>
                    `;
                });
                html += '</div>';
                contentArea.innerHTML = html;
            } else {
                contentArea.innerHTML = '<div class="empty-state"><i class="fas fa-comments"></i><h3>Aucune conversation</h3><p>Commencez à discuter avec d\'autres utilisateurs</p></div>';
            }
        });
}

function showConversationMessages(userId, username) {
    showNewMessageForm(username, true);
}

function loadMessages(type) {
    const contentArea = document.getElementById('contentArea');
    contentArea.innerHTML = '<div class="loading-state"><div class="spinner"></div><p>Chargement...</p></div>';
    
    fetch(`backend.php?action=get_messages&type=${type}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayMessages(data.data, type);
            }
        });
    
    document.querySelectorAll('.nav-item').forEach(btn => {
        btn.classList.remove('active');
    });
    event.target.classList.add('active');
}

function displayMessages(messages, type) {
    const contentArea = document.getElementById('contentArea');
    
    if (messages.length === 0) {
        contentArea.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>Aucun message</h3>
                <p>Votre boîte est vide pour le moment</p>
                <button onclick="showNewMessageForm()" class="btn-primary" style="margin-top: 20px;">
                    <i class="fas fa-plus"></i> Nouveau message
                </button>
            </div>
        `;
        return;
    }
    
    let html = '<div class="messages-list">';
    messages.forEach((message, index) => {
        const date = new Date(message.created_at).toLocaleString();
        const otherUser = type === 'inbox' ? message.sender_name : message.receiver_name;
        const isUnread = !message.is_read && type === 'inbox';
        
        html += `
            <div class="message-item ${isUnread ? 'unread' : ''}" 
                 style="animation-delay: ${index * 0.05}s"
                 onclick="viewMessage(${message.id})">
                <div class="message-header">
                    <strong><i class="fas fa-user"></i> ${otherUser}</strong>
                    <small><i class="far fa-clock"></i> ${date}</small>
                </div>
                <div class="message-subject">
                    ${message.subject || 'Sans sujet'}
                    ${isUnread ? '<span class="badge">Nouveau</span>' : ''}
                </div>
                <div class="message-preview">${message.content.substring(0, 100)}...</div>
                ${message.attachments_count > 0 ? `<div class="message-attachments"><i class="fas fa-paperclip"></i> ${message.attachments_count} pièce(s) jointe(s)</div>` : ''}
            </div>
        `;
    });
    html += '</div>';
    contentArea.innerHTML = html;
}

function viewMessage(messageId) {
    const contentArea = document.getElementById('contentArea');
    contentArea.innerHTML = '<div class="loading-state"><div class="spinner"></div><p>Chargement...</p></div>';
    
    fetch(`backend.php?action=read_message&id=${messageId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayMessageDetail(data.data);
            }
        });
}

function displayMessageDetail(message) {
    const date = new Date(message.created_at).toLocaleString();
    
    let attachmentsHtml = '';
    if (message.attachments && message.attachments.length > 0) {
        attachmentsHtml = '<div class="attachments-section"><h4>Pièces jointes</h4><div class="attachments-list">';
        message.attachments.forEach(att => {
            const fileIcon = getFileIcon(att.file_type);
            attachmentsHtml += `
                <div class="attachment-item" onclick="downloadFile(${att.id})">
                    <i class="fas ${fileIcon}"></i>
                    <div class="attachment-info">
                        <div class="attachment-name">${att.original_name}</div>
                        <div class="attachment-size">${formatFileSize(att.file_size)}</div>
                    </div>
                    <button class="download-btn"><i class="fas fa-download"></i></button>
                </div>
            `;
        });
        attachmentsHtml += '</div></div>';
    }
    
    const html = `
        <div class="message-detail">
            <div class="message-actions">
                <button onclick="loadMessages('inbox')" class="icon-btn">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <button onclick="deleteMessage(${message.id}, 'inbox')" class="icon-btn danger">
                    <i class="fas fa-trash"></i>
                </button>
                <button onclick="replyToMessage('${message.sender_name}')" class="icon-btn">
                    <i class="fas fa-reply"></i>
                </button>
            </div>
            
            <h3><i class="fas fa-envelope"></i> ${message.subject || 'Sans sujet'}</h3>
            
            <div class="message-meta">
                <div class="meta-row">
                    <i class="fas fa-user"></i>
                    <strong>De :</strong> ${message.sender_name}
                </div>
                <div class="meta-row">
                    <i class="fas fa-user"></i>
                    <strong>À :</strong> ${message.receiver_name}
                </div>
                <div class="meta-row">
                    <i class="far fa-calendar"></i>
                    <strong>Date :</strong> ${date}
                </div>
            </div>
            
            <div class="message-content">
                ${message.content.replace(/\n/g, '<br>')}
            </div>
            
            ${attachmentsHtml}
        </div>
    `;
    
    document.getElementById('contentArea').innerHTML = html;
}

function showNewMessageForm(toUsername = null, isReply = false) {
    const contentArea = document.getElementById('contentArea');
    pendingAttachments = [];
    
    // Charger la liste des utilisateurs
    fetch('backend.php?action=get_users')
        .then(response => response.json())
        .then(data => {
            let usersHtml = '<option value="">Sélectionner un destinataire</option>';
            if (data.success) {
                data.data.forEach(user => {
                    const selected = toUsername === user.username ? 'selected' : '';
                    usersHtml += `<option value="${user.username}" ${selected}>${user.username}</option>`;
                });
            }
            
            const html = `
                <div class="message-detail">
                    <h3><i class="fas fa-plus-circle"></i> ${isReply ? 'Répondre' : 'Nouveau message'}</h3>
                    
                    <form id="newMessageForm">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Destinataire</label>
                            <select id="to_username" required>
                                ${usersHtml}
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Sujet</label>
                            <input type="text" id="subject" placeholder="Sujet du message" ${isReply ? 'value="Re: "' : ''}>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-comment"></i> Message</label>
                            <textarea id="content" required placeholder="Votre message..."></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-paperclip"></i> Pièces jointes</label>
                            <div class="file-upload-area" onclick="document.getElementById('fileInput').click()">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <p>Cliquez pour ajouter des fichiers</p>
                                <small>Max 50MB par fichier</small>
                            </div>
                            <input type="file" id="fileInput" multiple style="display: none" onchange="handleFileSelect(this)">
                            <div id="attachmentsList" class="attachments-list"></div>
                        </div>
                        
                        <div style="display: flex; gap: 15px;">
                            <button type="submit" class="btn-submit">
                                <i class="fas fa-paper-plane"></i>
                                <span>Envoyer</span>
                            </button>
                            <button type="button" onclick="loadMessages('inbox')" class="btn-outline">
                                Annuler
                            </button>
                        </div>
                    </form>
                    
                    <div id="messageResult"></div>
                </div>
            `;
            
            contentArea.innerHTML = html;
            document.getElementById('newMessageForm').addEventListener('submit', sendMessageWithAttachments);
        });
}

function handleFileSelect(input) {
    const files = Array.from(input.files);
    const maxSize = 50 * 1024 * 1024; // 50MB
    
    for (const file of files) {
        if (file.size > maxSize) {
            showToast(`Le fichier ${file.name} dépasse 50MB`, 'error');
            continue;
        }
        
        // Upload du fichier
        const formData = new FormData();
        formData.append('file', file);
        
        fetch('backend.php?action=upload_file', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                pendingAttachments.push(data.data);
                updateAttachmentsList();
                showToast(`Fichier ${file.name} ajouté`, 'success');
            } else {
                showToast(`Erreur: ${data.message}`, 'error');
            }
        });
    }
    
    input.value = '';
}

function updateAttachmentsList() {
    const container = document.getElementById('attachmentsList');
    if (!container) return;
    
    if (pendingAttachments.length === 0) {
        container.innerHTML = '';
        return;
    }
    
    container.innerHTML = pendingAttachments.map((att, index) => `
        <div class="attachment-preview">
            <i class="fas ${getFileIcon(att.file_type)}"></i>
            <span>${att.original_name}</span>
            <button onclick="removeAttachment(${index})" class="remove-attachment">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `).join('');
}

function removeAttachment(index) {
    pendingAttachments.splice(index, 1);
    updateAttachmentsList();
}

function sendMessageWithAttachments(e) {
    e.preventDefault();
    
    const formData = {
        to_username: document.getElementById('to_username').value,
        subject: document.getElementById('subject').value,
        content: document.getElementById('content').value,
        attachments: pendingAttachments
    };
    
    if (!formData.to_username) {
        showToast('Veuillez sélectionner un destinataire', 'error');
        return;
    }
    
    if (!formData.content && formData.attachments.length === 0) {
        showToast('Veuillez saisir un message ou ajouter une pièce jointe', 'error');
        return;
    }
    
    const btn = e.target.querySelector('.btn-submit');
    btn.classList.add('loading');
    btn.disabled = true;
    
    fetch('backend.php?action=send_message', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(formData)
    })
    .then(response => response.json())
    .then(data => {
        btn.classList.remove('loading');
        btn.disabled = false;
        
        if (data.success) {
            showToast('Message envoyé avec succès !', 'success');
            pendingAttachments = [];
            setTimeout(() => {
                loadMessages('sent');
            }, 1500);
        } else {
            showToast(data.message, 'error');
        }
    })
    .catch(error => {
        btn.classList.remove('loading');
        btn.disabled = false;
        showToast('Erreur lors de l\'envoi', 'error');
    });
}

function downloadFile(fileId) {
    window.open(`backend.php?action=download_file&id=${fileId}`, '_blank');
}

function deleteMessage(messageId, type) {
    if (confirm('Êtes-vous sûr de vouloir supprimer ce message ?')) {
        fetch(`backend.php?action=delete_message&id=${messageId}&type=${type}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Message supprimé', 'success');
                    loadMessages(type);
                }
            });
    }
}

function replyToMessage(username) {
    showNewMessageForm(username, true);
}

function updateStatus(status) {
    fetch(`backend.php?action=update_status&status=${status}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const dot = document.querySelector('.status-dot');
                dot.className = `status-dot ${status}`;
                showToast('Statut mis à jour', 'success');
            }
        });
}

function logout() {
    if (confirm('Voulez-vous vraiment vous déconnecter ?')) {
        fetch('backend.php?action=logout')
            .then(response => response.json())
            .then(() => {
                window.location.href = 'login.html';
            });
    }
}

function showToast(message, type = 'info') {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = `toast-notification ${type}`;
    toast.classList.add('show');
    
    setTimeout(() => {
        toast.classList.remove('show');
    }, 3000);
}

function getFileIcon(extension) {
    const icons = {
        'jpg': 'fa-file-image', 'jpeg': 'fa-file-image', 'png': 'fa-file-image', 'gif': 'fa-file-image',
        'pdf': 'fa-file-pdf', 'doc': 'fa-file-word', 'docx': 'fa-file-word',
        'xls': 'fa-file-excel', 'xlsx': 'fa-file-excel',
        'ppt': 'fa-file-powerpoint', 'pptx': 'fa-file-powerpoint',
        'txt': 'fa-file-alt', 'zip': 'fa-file-archive', 'rar': 'fa-file-archive'
    };
    return icons[extension.toLowerCase()] || 'fa-file';
}

function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}