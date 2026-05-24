<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { CheckCircle2, Inbox, Loader2, XCircle } from 'lucide-vue-next';
import { knotApi, type ApprovalSummary } from '../lib/api';

const approvals = ref<ApprovalSummary[]>([]);
const loading = ref(false);
const message = ref<string | null>(null);

async function load() {
  loading.value = true;
  try {
    approvals.value = (await knotApi.listApprovals()).approvals;
  } finally {
    loading.value = false;
  }
}

async function decide(approval: ApprovalSummary, status: 'approved' | 'rejected') {
  await knotApi.decideApproval(approval.id, status);
  message.value = `Approval #${approval.id} ${status}.`;
  await load();
}

onMounted(load);
</script>

<template>
  <div class="k-p-6 k-max-w-[1100px] k-mx-auto k-space-y-5">
    <header class="k-flex k-items-center k-justify-between k-gap-3">
      <div class="k-flex k-items-center k-gap-3">
        <div class="k-h-10 k-w-10 k-rounded-knot-sm k-bg-knot-primary-soft k-text-knot-primary k-flex k-items-center k-justify-center">
          <Inbox :size="20" />
        </div>
        <div>
          <h1 class="k-text-2xl k-font-bold k-text-knot-text">Approval inbox</h1>
          <p class="k-text-sm k-text-knot-text-muted">Human-in-the-loop decisions waiting for action.</p>
        </div>
      </div>
      <button
        @click="load"
        class="k-inline-flex k-items-center k-gap-2 k-px-3 k-py-1.5 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border k-text-knot-text hover:k-border-knot-primary"
      >
        <Loader2 v-if="loading" :size="14" class="k-animate-spin" />
        Refresh
      </button>
    </header>

    <div v-if="message" class="k-bg-knot-success-soft k-text-knot-success k-px-4 k-py-3 k-rounded-knot-sm k-text-sm">{{ message }}</div>

    <div v-if="!approvals.length && !loading" class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-8 k-text-center k-text-knot-text-muted">
      No pending approvals.
    </div>

    <article
      v-for="approval in approvals"
      :key="approval.id"
      class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-4 k-space-y-3"
    >
      <div class="k-flex k-items-start k-justify-between k-gap-3">
        <div>
          <div class="k-text-xs k-font-mono k-text-knot-text-soft">Approval #{{ approval.id }} · node {{ approval.nodeId }}</div>
          <h2 class="k-text-base k-font-semibold k-text-knot-text k-mt-1">{{ approval.message }}</h2>
          <p class="k-text-xs k-text-knot-text-muted k-mt-1">Execution #{{ approval.executionId ?? '—' }} · {{ approval.createdAt }}</p>
        </div>
        <span class="k-px-2 k-py-0.5 k-rounded-knot-pill k-bg-knot-warning-soft k-text-knot-warning k-text-xs k-font-semibold">
          pending
        </span>
      </div>
      <div class="k-flex k-items-center k-gap-2">
        <button
          @click="decide(approval, 'approved')"
          class="k-inline-flex k-items-center k-gap-1.5 k-px-3 k-py-1.5 k-rounded-knot-sm k-bg-knot-success-soft k-text-knot-success k-font-semibold k-text-sm hover:k-bg-knot-success hover:k-text-white"
        >
          <CheckCircle2 :size="14" /> Approve
        </button>
        <button
          @click="decide(approval, 'rejected')"
          class="k-inline-flex k-items-center k-gap-1.5 k-px-3 k-py-1.5 k-rounded-knot-sm k-bg-knot-danger-soft k-text-knot-danger k-font-semibold k-text-sm hover:k-bg-knot-danger hover:k-text-white"
        >
          <XCircle :size="14" /> Reject
        </button>
      </div>
    </article>
  </div>
</template>
