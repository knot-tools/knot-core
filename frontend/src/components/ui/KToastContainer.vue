<script setup lang="ts">
import { computed } from 'vue';
import KToast from './KToast.vue';
import { useToast } from '../../composables/useToast';
import { KNOT_Z_TOAST, knotViewportTopOffset } from '../../lib/overlayStacking';

const toastApi = useToast();
const toastList = computed(() => toastApi.toasts.value);
const stackStyle = {
  top: knotViewportTopOffset(0.5),
  zIndex: KNOT_Z_TOAST,
};
</script>

<template>
  <Teleport to="body">
    <div
      data-knot-test="knot-toast-stack"
      class="k-fixed k-right-4 k-flex k-flex-col k-gap-2 k-max-w-sm k-w-full k-pointer-events-none"
      :style="stackStyle"
      aria-live="polite"
    >
      <KToast
        v-for="item in toastList"
        :key="item.id"
        class="k-pointer-events-auto"
        :level="item.level"
        :title="item.title"
        :body="item.body"
        :duration="item.duration"
        :sticky="item.sticky"
        @dismiss="toastApi.dismiss(item.id)"
      />
    </div>
  </Teleport>
</template>
