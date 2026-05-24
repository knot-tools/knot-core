<!--
  DarkModeToggle — toggles knot-theme=dark on <html>, persisted in localStorage.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Sun, Moon } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const dark = ref(false);

function apply() {
  document.documentElement.dataset.knotTheme = dark.value ? 'dark' : 'light';
}

function toggle() {
  dark.value = !dark.value;
  localStorage.setItem('knot.theme', dark.value ? 'dark' : 'light');
  apply();
}

onMounted(() => {
  const saved = localStorage.getItem('knot.theme');
  dark.value = saved === 'dark';
  apply();
});
</script>

<template>
  <button
    @click="toggle"
    :aria-label="dark ? t('theme.lightAria') : t('theme.darkAria')"
    class="k-p-2 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border k-text-knot-text hover:k-border-knot-primary"
  >
    <Sun v-if="dark" :size="14" />
    <Moon v-else :size="14" />
  </button>
</template>
