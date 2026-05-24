// Knot frontend ESLint — targeted rules only (full stylistic lint deferred).
// Immutability: vue/no-mutating-props is the product-critical guardrail from V2.8 audit.
import globals from 'globals';
import pluginVue from 'eslint-plugin-vue';
import tseslint from 'typescript-eslint';
import vueParser from 'vue-eslint-parser';

export default tseslint.config(
  { ignores: ['dist/**', 'node_modules/**'] },
  {
    files: ['src/**/*.vue'],
    languageOptions: {
      globals: { ...globals.browser },
      parser: vueParser,
      parserOptions: {
        parser: tseslint.parser,
        ecmaVersion: 'latest',
        sourceType: 'module',
      },
    },
    plugins: { vue: pluginVue },
    rules: {
      'vue/no-mutating-props': 'error',
      'vue/multi-word-component-names': 'off',
    },
  },
);
