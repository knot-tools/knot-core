/// <reference types="vite/client" />

declare global {
  interface Window {
    KNOT_MARKETPLACE_UI_ENABLED?: boolean;
    KNOT_ENTITY?: number;
  }
}

declare module '*.vue' {
  import type { DefineComponent } from 'vue';
  const component: DefineComponent<{}, {}, any>;
  export default component;
}
