<!--
  ADR-20 slice 3 — mount point for a Knot Core extension.

  Renders a single DOM container and asks `window.KnotCore` to mount
  the named extension into it. If the extension bundle has not yet
  registered (the `<script defer>` of the extension may still be
  evaluating), we listen for the `knot:extension-registered` event
  and retry once.

  The placeholder visible while we wait is intentionally minimal —
  most extensions ship their own splash inside `mount()`. Errors are
  surfaced inline so beta testers can see what happened without
  reaching for devtools.
-->
<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{
  extensionId: string;
}>();

const containerRef = ref<HTMLElement | null>(null);
const stateRef = ref<'pending' | 'mounted' | 'missing-core' | 'missing-extension' | 'failed'>('pending');
const messageRef = ref<string>('');

const listenForRegistration = (target: HTMLElement): void => {
  const handler = (event: Event): void => {
    const detail = (event as CustomEvent<{ id?: string }>).detail;
    if (!detail || detail.id !== props.extensionId) {
      return;
    }
    window.removeEventListener('knot:extension-registered', handler as EventListener);
    tryMount(target);
  };
  window.addEventListener('knot:extension-registered', handler as EventListener);
  // Safety: stop listening on unmount.
  onBeforeUnmount(() => {
    window.removeEventListener('knot:extension-registered', handler as EventListener);
  });
};

const tryMount = (target: HTMLElement): void => {
  const core = (window as unknown as { KnotCore?: { mountExtension: (id: string, el: HTMLElement) => boolean; hasExtensionRegistered: (id: string) => boolean; extension: (id: string) => unknown } }).KnotCore;
  if (!core) {
    stateRef.value = 'missing-core';
    messageRef.value = 'window.KnotCore is not available — Core boot order issue.';
    return;
  }
  if (!core.extension(props.extensionId)) {
    stateRef.value = 'missing-extension';
    messageRef.value = `Extension "${props.extensionId}" is not in the active list.`;
    return;
  }
  if (!core.hasExtensionRegistered(props.extensionId)) {
    // Bundle still loading — wait for the registration event.
    listenForRegistration(target);
    return;
  }
  const ok = core.mountExtension(props.extensionId, target);
  if (!ok) {
    stateRef.value = 'failed';
    messageRef.value = `mountExtension("${props.extensionId}") returned false; see console.`;
    return;
  }
  stateRef.value = 'mounted';
};

onMounted(() => {
  if (containerRef.value) {
    tryMount(containerRef.value);
  }
});

onBeforeUnmount(() => {
  if (containerRef.value && stateRef.value === 'mounted') {
    const core = (window as unknown as { KnotCore?: { unmountExtension: (id: string, el: HTMLElement) => void } }).KnotCore;
    core?.unmountExtension(props.extensionId, containerRef.value);
  }
});
</script>

<template>
  <div class="k-relative k-min-h-[200px]" :data-knot-extension-id="extensionId">
    <div
      ref="containerRef"
      class="k-knot-extension-host"
      :data-knot-extension-state="stateRef"
    ></div>
    <div
      v-if="stateRef === 'pending'"
      class="k-absolute k-inset-0 k-flex k-items-center k-justify-center k-text-knot-text-secondary k-text-sm"
      data-knot-extension-placeholder="loading"
    >
      Loading extension…
    </div>
    <div
      v-else-if="stateRef !== 'mounted'"
      class="k-mx-auto k-mt-12 k-max-w-xl k-rounded-lg k-border k-border-knot-warning/40 k-bg-knot-warning/10 k-p-4 k-text-sm k-text-knot-warning-strong"
      role="alert"
      :data-knot-extension-error="stateRef"
    >
      <strong class="k-block k-mb-1">Extension could not start</strong>
      <span>{{ messageRef }}</span>
    </div>
  </div>
</template>
