import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import { defineComponent, h } from "vue";
import { createI18n, Translation } from "vue-i18n";
import { cspSafeMessageCompiler } from "../cspSafeMessageCompiler";

describe("i18n-t with the CSP-safe compiler", () => {
  it("renders the link slot inside the sentence", () => {
    const i18n = createI18n({
      legacy: false,
      locale: "fr_FR",
      messageCompiler: cspSafeMessageCompiler,
      messages: { fr_FR: { hint: "Créez-en un depuis l'écran {link}." } },
    });
    const Host = defineComponent({
      render: () => h(Translation, { keypath: "hint", tag: "span" }, { link: () => h("a", { href: "?mode=credentials" }, "Identifiants") }),
    });
    const wrapper = mount(Host, { global: { plugins: [i18n] } });
    expect(wrapper.html()).toContain("<a href=\"?mode=credentials\">Identifiants</a>");
    expect(wrapper.text()).toBe("Créez-en un depuis l'écran Identifiants.");
  });
});
