<template>
    <PMLayout>
        <template #header>
            <h1>Messages</h1>
        </template>

        <section class="main-panel">
            <div class="panel-card">
                <div v-if="conversations.data?.length" class="conversation-list">
                    <div v-for="conv in conversations.data" :key="conv.id" class="conversation-item">
                        <div class="conv-info">
                            <h4>{{ conv.subject }}</h4>
                            <span class="sub-text">
                                <span v-if="conv.service_request">Job: {{ conv.service_request.job_reference || conv.service_request.request_id }}</span>
                                · {{ conv.type }}
                            </span>
                        </div>
                        <div class="conv-preview" v-if="conv.latest_message">
                            <span class="sender">{{ conv.latest_message.sender?.name }}:</span>
                            {{ conv.latest_message.body?.substring(0, 80) }}{{ conv.latest_message.body?.length > 80 ? '...' : '' }}
                        </div>
                        <span v-if="conv.messages_count" class="msg-count">{{ conv.messages_count }} messages</span>
                    </div>
                </div>
                <div v-else class="empty-state">
                    <i class="fas fa-comments"></i>
                    <p>No conversations yet.</p>
                </div>
            </div>
        </section>
    </PMLayout>
</template>

<script setup>
import PMLayout from '../../Layouts/PMLayout.vue';

defineProps({
    conversations: { type: Object, default: () => ({ data: [] }) },
});

defineOptions({ layout: null });
</script>

<style scoped>
.conversation-list { display: flex; flex-direction: column; }
.conversation-item { padding: 1rem; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; gap: 1rem; cursor: pointer; transition: background 0.2s; }
.conversation-item:hover { background: #f9fafb; }
.conv-info h4 { margin: 0 0 0.25rem; font-size: 1rem; }
.conv-preview { font-size: 0.85rem; color: #6B7280; flex: 1; }
.conv-preview .sender { font-weight: 600; color: #374151; }
.msg-count { font-size: 0.8rem; background: #053272; color: white; padding: 0.2rem 0.6rem; border-radius: 12px; white-space: nowrap; }
.empty-state { text-align: center; padding: 3rem; color: #9CA3AF; }
.empty-state i { font-size: 2.5rem; margin-bottom: 1rem; display: block; }
</style>
