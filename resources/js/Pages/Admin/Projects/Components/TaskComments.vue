<template>
    <div class="comments-container">
        <div class="comments-list">
            <div v-for="comment in comments" :key="comment.id" class="comment-row">
                <!-- Avatar Column -->
                <div class="avatar-col">
                    <div class="user-avatar" :style="{ backgroundColor: getAvatarColor(comment.user.name) }">
                        {{ getInitials(comment.user.name) }}
                    </div>
                </div>

                <!-- Comment Bubble Column -->
                <div class="bubble-col">
                    <div class="comment-bubble">
                        <div class="bubble-header">
                            <span class="user-name">{{ comment.user.name }}</span>
                            <span class="time">{{ formatDate(comment.created_at) }}</span>
                            
                            <!-- Action Buttons -->
                            <div class="bubble-actions">
                                <button class="action-btn" @click.stop="toggleReaction(comment.id, '👍')" title="Like">
                                    <i class="far fa-thumbs-up"></i>
                                </button>
                                <button class="action-btn" @click.stop="toggleReaction(comment.id, '❤️')" title="Love">
                                    <i class="far fa-heart"></i>
                                </button>
                                
                                <button v-if="canDelete(comment)" class="action-btn text-red-500" @click="deleteComment(comment.id)" title="Delete">
                                    <i class="far fa-trash-alt"></i>
                                </button>
                            </div>
                        </div>

                        <div class="comment-content" v-html="formatContent(comment.content)"></div>
                        
                        <!-- Attachments -->
                        <div v-if="comment.attachments && comment.attachments.length" class="attachments-grid">
                            <div v-for="file in comment.attachments" :key="file.id" class="attachment-card">
                                <div class="file-icon-box">
                                    <i class="fas fa-file-pdf" v-if="file.file_name.endsWith('.pdf')"></i>
                                    <i class="fas fa-file-image" v-else-if="file.file_name.match(/\.(jpg|jpeg|png|gif)$/)"></i>
                                    <i class="fas fa-file-word" v-else-if="file.file_name.match(/\.(doc|docx)$/)"></i>
                                    <i class="fas fa-file" v-else></i>
                                </div>
                                <div class="file-details">
                                    <span class="file-name" :title="file.file_name">{{ file.file_name }}</span>
                                    <span class="file-ext">{{ file.file_name.split('.').pop().toUpperCase() }}</span>
                                </div>
                                <a :href="`/admin/projects/files/${file.id}/download`" target="_blank" class="download-cover"></a>
                            </div>
                        </div>

                        <!-- Reactions Display -->
                        <div v-if="comment.reactions && comment.reactions.length" class="reactions-row">
                            <button 
                                v-for="(count, emoji) in groupReactions(comment.reactions)" 
                                :key="emoji" 
                                class="reaction-chip"
                                :class="{ 'active': hasUserReacted(comment.reactions, emoji) }"
                                @click="toggleReaction(comment.id, emoji)"
                            >
                                <span class="emoji">{{ emoji }}</span>
                                <span class="count">{{ count }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="comments.length === 0" class="no-comments">
                No comments yet.
            </div>
        </div>
        
        <div class="add-comment-form">
            <div class="toolbar">
                <label class="tool-btn" title="Attach File">
                    <i class="fas fa-paperclip"></i>
                    <input type="file" @change="handleFileSelect" multiple hidden>
                </label>
                <button class="tool-btn" @click="triggerMention" title="Mention User @">
                    <i class="fas fa-at"></i>
                </button>
                <button class="tool-btn" @click="triggerEmoji" title="Add Emoji">
                    <i class="far fa-smile"></i>
                </button>
            </div>

            <textarea 
                ref="textarea"
                v-model="newComment" 
                placeholder="Write a comment... (@ to mention)" 
                class="comment-input"
                rows="3"
                @keyup="checkMention"
                @keyup.ctrl.enter="submitComment"
            ></textarea>
            
             <!-- Mentions Dropdown -->
             <div v-if="showMentions" class="mentions-dropdown" :style="mentionPosition">
                <div 
                    v-for="user in filteredUsers" 
                    :key="user.id" 
                    class="mention-item"
                    @click="insertMention(user)"
                >
                    {{ user.name }}
                </div>
            </div>

            <div v-if="selectedFiles.length" class="selected-files">
                <div v-for="(file, index) in selectedFiles" :key="index" class="selected-file-chip">
                    <span>{{ file.name }}</span>
                    <button @click="removeFile(index)" class="remove-file-btn">&times;</button>
                </div>
            </div>

            <div class="form-actions">
                <button 
                    @click="submitComment" 
                    class="btn btn-primary btn-sm"
                    :disabled="!newComment.trim() && !selectedFiles.length"
                >
                    Send
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, nextTick } from 'vue'
import { format } from 'date-fns'
import { router, usePage } from '@inertiajs/vue3'

const props = defineProps({
    taskId: {
        type: Number,
        required: true
    },
    comments: {
        type: Array,
        default: () => []
    }
})

const page = usePage()
const users = computed(() => page.props.users || [])
const currentUser = computed(() => page.props.auth.user)

const newComment = ref('')
const textarea = ref(null)
const selectedFiles = ref([])

// Mentions Logic
const showMentions = ref(false)
const mentionSearch = ref('')
const mentionPosition = ref({ top: '0px', left: '0px' })

const filteredUsers = computed(() => {
    if (!mentionSearch.value) return users.value.slice(0, 5)
    return users.value.filter(u => u.name.toLowerCase().includes(mentionSearch.value.toLowerCase())).slice(0, 5)
})

const getInitials = (name) => {
    if (!name) return '?'
    return name.split(' ').map(n => n[0]).slice(0, 2).join('').toUpperCase()
}

const getAvatarColor = (name) => {
    const colors = ['#f472b6', '#3b82f6', '#10b981', '#f59e0b', '#8b5cf6']
    let hash = 0
    for (let i = 0; i < name.length; i++) {
        hash = name.charCodeAt(i) + ((hash << 5) - hash)
    }
    return colors[Math.abs(hash) % colors.length]
}

const checkMention = (e) => {
    const cursor = e.target.selectionStart
    const textBefore = newComment.value.substring(0, cursor)
    const match = textBefore.match(/@(\w*)$/)
    
    if (match) {
        showMentions.value = true
        mentionSearch.value = match[1]
        
        const coords = getCursorXY(textarea.value, cursor)
        mentionPosition.value = { 
            top: `${coords.y + 20}px`, 
            left: `${coords.x}px` 
        }
    } else {
        showMentions.value = false
    }
}

const getCursorXY = (input, selectionPoint) => {
    const { offsetLeft: inputX, offsetTop: inputY } = input
    const div = document.createElement('div')
    const copyStyle = getComputedStyle(input)
    for (const prop of copyStyle) {
        div.style[prop] = copyStyle[prop]
    }
    div.style.height = 'auto'
    div.style.minHeight = 'auto'
    div.style.width = copyStyle.width
    div.style.position = 'absolute'
    div.style.visibility = 'hidden'
    div.style.whiteSpace = 'pre-wrap'
    div.style.wordWrap = 'break-word' 
    const inputValue = input.value.substring(0, selectionPoint)
    const textContent = document.createTextNode(inputValue)
    const span = document.createElement('span')
    span.textContent = input.value.substring(selectionPoint) || '.'
    div.appendChild(textContent)
    div.appendChild(span)
    document.body.appendChild(div)
    const { offsetLeft: spanX, offsetTop: spanY } = span
    document.body.removeChild(div)
    return { x: inputX + spanX, y: inputY + spanY }
}

const triggerMention = () => {
    newComment.value += '@'
    nextTick(() => {
        textarea.value.focus()
        textarea.value.dispatchEvent(new Event('keyup'))
    })
}

const insertMention = (user) => {
    const cursor = textarea.value.selectionStart
    const textBefore = newComment.value.substring(0, cursor)
    const textAfter = newComment.value.substring(cursor)
    newComment.value = textBefore.replace(/@(\w*)$/, `@${user.name} `) + textAfter
    showMentions.value = false
    nextTick(() => textarea.value.focus())
}

const triggerEmoji = () => {
    newComment.value += '👍'
}

const handleFileSelect = (e) => {
    selectedFiles.value.push(...Array.from(e.target.files))
}

const removeFile = (index) => {
    selectedFiles.value.splice(index, 1)
}

const formatDate = (date) => {
    return format(new Date(date), 'MMM d h:mm a')
}

const formatContent = (content) => {
    let formatted = content.replace(/\n/g, '<br>')
    formatted = formatted.replace(/@(\w+(?:\s+\w+)*)/g, '<span class="mention-pill">@$1</span>')
    return formatted
}

const canDelete = (comment) => {
    return comment.user_id === currentUser.value?.id || currentUser.value?.role === 'admin'
}

const deleteComment = (commentId) => {
    if (!confirm('Are you sure you want to delete this comment?')) return
    router.delete(`/admin/comments/${commentId}`, {
        preserveScroll: true
    })
}

const toggleReaction = (commentId, emoji) => {
    router.post(`/admin/comments/${commentId}/reaction`, { emoji }, {
        preserveScroll: true
    })
}

const groupReactions = (reactions) => {
    if (!reactions) return {}
    const counts = {}
    reactions.forEach(r => {
        counts[r.emoji] = (counts[r.emoji] || 0) + 1
    })
    return counts
}

const hasUserReacted = (reactions, emoji) => {
    if (!reactions || !currentUser.value) return false
    return reactions.some(r => r.user_id === currentUser.value.id && r.emoji === emoji)
}

const submitComment = () => {
    if (!newComment.value.trim() && !selectedFiles.value.length) return
    
    if (selectedFiles.value.length > 0) {
        selectedFiles.value.forEach(file => {
             const fd = new FormData()
             fd.append('file', file)
             fd.append('fileable_type', 'task')
             fd.append('fileable_id', props.taskId)
             router.post('/admin/projects/upload-file', fd)
        })
    }

    router.post(`/admin/tasks/${props.taskId}/comments`, {
        content: newComment.value
    }, {
        preserveScroll: true,
        onSuccess: () => {
            newComment.value = ''
            selectedFiles.value = []
        }
    })
}
</script>

<style scoped>
.comments-container {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.comment-row {
    display: flex;
    gap: 1rem;
    align-items: flex-start;
    margin-bottom: 1rem;
}

.avatar-col {
    flex-shrink: 0;
}

.user-avatar {
    width: 36px;
    height: 36px;
    border-radius: 6px;
    background: #f472b6;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 0.9rem;
}

.bubble-col {
    flex: 1;
    min-width: 0;
}

.comment-bubble {
    background: #f3f6fc;
    border-radius: 0 12px 12px 12px;
    padding: 1rem;
    position: relative;
    border: 1px solid transparent;
}

.comment-bubble:hover {
    background: #eef2f8; 
}

.comment-bubble:hover .bubble-actions {
    opacity: 1;
    pointer-events: auto;
}

.bubble-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.25rem;
    position: relative;
}

.user-name {
    font-weight: 700;
    color: #1f2937;
    font-size: 0.95rem;
}

.time {
    color: #9ca3af;
    font-size: 0.85rem;
}

.bubble-actions {
    position: absolute;
    top: -10px;
    right: -5px;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 2px;
    display: flex;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    opacity: 0; /* Hidden by default */
    pointer-events: none;
    transition: opacity 0.2s;
}

.action-btn {
    border: none;
    background: none;
    color: #6b7280;
    padding: 4px 8px;
    cursor: pointer;
    font-size: 0.9rem;
}

.action-btn:hover {
    color: #374151;
    background: #f3f4f6;
    border-radius: 4px;
}

.comment-content {
    color: #374151;
    font-size: 0.95rem;
    line-height: 1.5;
    word-break: break-word;
}

:deep(.mention-pill) {
    background: #dbeafe;
    color: #1e40af;
    padding: 1px 6px;
    border-radius: 12px;
    font-weight: 600;
    display: inline-block;
}

.attachments-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    margin-top: 1rem;
}

.attachment-card {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    width: 140px;
    padding: 1rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    position: relative;
    transition: box-shadow 0.2s;
}

.attachment-card:hover {
    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
}

.file-icon-box {
    font-size: 2.5rem;
    color: #ef4444; 
    margin-bottom: 0.5rem;
}

.file-icon-box .fa-file-image { color: #8b5cf6; }
.file-icon-box .fa-file-word { color: #3b82f6; }

.file-details {
    display: flex;
    flex-direction: column;
    width: 100%;
}

.file-name {
    font-weight: 500;
    color: #374151;
    font-size: 0.85rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.file-ext {
    font-size: 0.75rem;
    color: #9ca3af;
    margin-top: 2px;
}

.download-cover {
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
}

/* Reactions Styles */
.reactions-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.reaction-chip {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 2px 8px;
    display: flex;
    align-items: center;
    gap: 0.25rem;
    font-size: 0.85rem;
    cursor: pointer;
    transition: all 0.2s;
}

.reaction-chip:hover {
    background: #f9fafb;
    border-color: #d1d5db;
}

.reaction-chip.active {
    background: #e0e7ff; /* light blue */
    border-color: #6366f1; /* purple/blue */
    color: #4338ca;
}

/* Add Form Layout */
.add-comment-form {
    position: relative;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: white;
}

.toolbar {
    display: flex; gap: 0.5rem; padding: 0.5rem; background: #f9fafb; border-bottom: 1px solid #e5e7eb; border-radius: 8px 8px 0 0;
}
.comment-input {
    border: none; border-radius: 0 0 8px 8px; padding: 1rem; font-family: inherit; width: 100%; box-sizing: border-box;
}
.comment-input:focus { ring: none; border: none; outline: none; }
.form-actions { padding: 0.5rem 1rem; display: flex; justify-content: flex-end; }
.mentions-dropdown {
    position: absolute; background: white; border: 1px solid #e5e7eb; box-shadow: 0 4px 12px rgba(0,0,0,0.1); width: 200px; z-index: 100; border-radius: 6px; overflow: hidden;
}
.mention-item { padding: 0.75rem; cursor: pointer; border-bottom: 1px solid #f3f4f6; }
.mention-item:hover { background: #f3f4f6; }
.selected-files { padding: 0.5rem; display: flex; gap: 0.5rem; flex-wrap: wrap; }
.selected-file-chip { background: #f3f4f6; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem; display: flex; align-items: center; gap: 0.5rem; }
.remove-file-btn { border: none; background: none; cursor: pointer; color: #6b7280; font-weight: bold; }

/* Button styles */
.btn { padding: 0.5rem 1rem; border-radius: 6px; font-weight: 600; cursor: pointer; border: none; transition: all 0.2s; }
.btn-primary { background: #053272; color: white; }
.btn-primary:hover:not(:disabled) { background: #042454; }
.btn-primary:disabled { background: #9ca3af; color: #e5e7eb; cursor: not-allowed; }
.btn-sm { padding: 0.4rem 0.8rem; font-size: 0.85rem; }
</style>
