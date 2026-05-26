/**
 * Knot Marketplace block registry (internal mapping type → Vue SFC).
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import type { Component } from 'vue';

import BannerBlock from './BannerBlock.vue';
import ComparePlansBlock from './ComparePlansBlock.vue';
import CtaBlock from './CtaBlock.vue';
import EcosystemBlock from './EcosystemBlock.vue';
import FaqBlock from './FaqBlock.vue';
import FeaturedTemplatesBlock from './FeaturedTemplatesBlock.vue';
import FeaturesBlock from './FeaturesBlock.vue';
import GalleryBlock from './GalleryBlock.vue';
import HeroBlock from './HeroBlock.vue';
import HomeDiscoveryBlock from './HomeDiscoveryBlock.vue';
import HighlightBlock from './HighlightBlock.vue';
import HowItWorksBlock from './HowItWorksBlock.vue';
import NewsHeaderBlock from './NewsHeaderBlock.vue';
import NewsListBlock from './NewsListBlock.vue';
import PrerequisitesBlock from './PrerequisitesBlock.vue';
import ProductCardBlock from './ProductCardBlock.vue';
import ProductHeaderBlock from './ProductHeaderBlock.vue';
import RelatedNewsBlock from './RelatedNewsBlock.vue';
import RequiredProductsBlock from './RequiredProductsBlock.vue';
import ResourcesBlock from './ResourcesBlock.vue';
import RichTextBlock from './RichTextBlock.vue';
import StickyCtaBlock from './StickyCtaBlock.vue';
import StorefrontTabsBlock from './StorefrontTabsBlock.vue';
import TemplateGridBlock from './TemplateGridBlock.vue';
import TemplateHeaderBlock from './TemplateHeaderBlock.vue';
import UseCasesGridBlock from './UseCasesGridBlock.vue';
import VideoBlock from './VideoBlock.vue';
import WorkflowFullPreviewBlock from './WorkflowFullPreviewBlock.vue';
import WorkflowMiniPreview from './WorkflowMiniPreview.vue';

/** Internal registry keyed by editorial `BlockSpec.type`. */
export const marketplaceBlockRegistry: Record<string, Component> = {
  banner: BannerBlock,
  hero: HeroBlock,
  highlight: HighlightBlock,
  ecosystem: EcosystemBlock,
  product_card: ProductCardBlock,
  productCard: ProductCardBlock,
  template_grid: TemplateGridBlock,
  templateGrid: TemplateGridBlock,
  workflow_mini: WorkflowMiniPreview,
  workflowMini: WorkflowMiniPreview,
  storefront_tabs: StorefrontTabsBlock,
  storefrontTabs: StorefrontTabsBlock,
  home_discovery: HomeDiscoveryBlock,
  homeDiscovery: HomeDiscoveryBlock,

  news_list: NewsListBlock,
  newsList: NewsListBlock,
  featured_templates: FeaturedTemplatesBlock,
  featuredTemplates: FeaturedTemplatesBlock,
  resources: ResourcesBlock,
  product_header: ProductHeaderBlock,
  productHeader: ProductHeaderBlock,
  gallery: GalleryBlock,
  video: VideoBlock,
  features: FeaturesBlock,
  faq: FaqBlock,
  sticky_cta: StickyCtaBlock,
  stickyCta: StickyCtaBlock,
  cta: CtaBlock,

  template_header: TemplateHeaderBlock,
  templateHeader: TemplateHeaderBlock,
  workflow_full_preview: WorkflowFullPreviewBlock,
  workflowFullPreview: WorkflowFullPreviewBlock,
  how_it_works: HowItWorksBlock,
  howItWorks: HowItWorksBlock,
  prerequisites: PrerequisitesBlock,
  required_products: RequiredProductsBlock,
  requiredProducts: RequiredProductsBlock,
  compare_plans: ComparePlansBlock,
  comparePlans: ComparePlansBlock,
  news_header: NewsHeaderBlock,
  newsHeader: NewsHeaderBlock,
  rich_text: RichTextBlock,
  richText: RichTextBlock,
  related_news: RelatedNewsBlock,
  relatedNews: RelatedNewsBlock,
  use_cases_grid: UseCasesGridBlock,
  useCasesGrid: UseCasesGridBlock,
};

export function resolveMarketplaceBlock(type: string | undefined): Component | null {
  if (!type) {
    return null;
  }
  return marketplaceBlockRegistry[type] ?? null;
}
