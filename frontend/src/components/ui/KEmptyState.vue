<script setup lang="ts">
/**
 * KEmptyState — Friendly placeholder when a list / area has nothing yet.
 *
 * Visual goal: a calm centred block with an illustration slot,
 * a human title, a helpful body and an optional primary action.
 * No "No data found" robotic phrasing — the title slot is meant
 * to be filled with sentences like "Aucun workflow encore — créons-en
 * un ensemble".
 *
 * Usage:
 *   <KEmptyState
 *     title="Pas encore de workflow"
 *     body="Crée ton premier workflow pour automatiser une tâche
 *           récurrente. Ça prend 5 minutes."
 *     action-label="Créer un workflow"
 *     @action="$emit('create')"
 *   >
 *     <template #icon><WorkflowIcon /></template>
 *   </KEmptyState>
 */

defineProps<{
  /** Human title — full sentence preferred, not a label. */
  title: string;
  /** Helpful body — one or two sentences explaining what this area is for. */
  body?: string;
  /** Optional CTA label. When omitted, the action button is hidden. */
  actionLabel?: string;
  /** Optional secondary CTA label, rendered as a link-style button. */
  secondaryLabel?: string;
  /** Optional tertiary CTA (e.g. starter import), link-style. */
  tertiaryLabel?: string;
  /** Reduce vertical padding for empty states inside small panels. */
  compact?: boolean;
}>();

defineEmits<{
  (event: 'action'): void;
  (event: 'secondary'): void;
  (event: 'tertiary'): void;
}>();
</script>

<template>
  <div
    class="k-flex k-flex-col k-items-center k-justify-center k-text-center"
    :class="compact ? 'k-py-6 k-gap-2' : 'k-py-12 k-gap-4'"
    data-testid="k-empty-state"
  >
    <div
      class="k-flex k-items-center k-justify-center k-rounded-knot-pill"
      :class="compact ? 'k-w-10 k-h-10' : 'k-w-14 k-h-14'"
      :style="{
        backgroundColor: 'var(--knot-color-primary-soft)',
        color: 'var(--knot-color-primary)',
      }"
    >
      <slot name="icon" />
    </div>

    <h3
      class="k-font-semibold k-text-knot k-m-0"
      :class="compact ? 'k-text-base' : 'k-text-lg'"
    >
      {{ title }}
    </h3>

    <p
      v-if="body"
      class="k-text-knot-text-muted k-text-sm k-max-w-sm k-leading-relaxed k-m-0"
    >
      {{ body }}
    </p>

    <div
      v-if="actionLabel || secondaryLabel || tertiaryLabel || $slots.actions"
      class="k-flex k-flex-wrap k-items-center k-justify-center k-gap-3 k-mt-2"
    >
      <slot name="actions">
        <button
          v-if="actionLabel"
          type="button"
          class="k-bg-knot-primary k-text-white k-px-4 k-py-2 k-rounded-knot-sm k-text-sm k-font-medium k-shadow-knot-sm hover:k-bg-knot-primary-strong k-transition-colors k-duration-knot"
          data-testid="k-empty-state-action"
          @click="$emit('action')"
        >
          {{ actionLabel }}
        </button>
        <button
          v-if="secondaryLabel"
          type="button"
          class="k-text-knot-primary k-text-sm k-font-medium hover:k-underline"
          data-testid="k-empty-state-secondary"
          @click="$emit('secondary')"
        >
          {{ secondaryLabel }}
        </button>
        <button
          v-if="tertiaryLabel"
          type="button"
          class="k-text-knot-text-muted k-text-sm k-font-medium hover:k-text-knot-primary hover:k-underline"
          data-testid="k-empty-state-tertiary"
          @click="$emit('tertiary')"
        >
          {{ tertiaryLabel }}
        </button>
      </slot>
    </div>
  </div>
</template>
