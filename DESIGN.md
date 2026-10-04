---
name: Loop Oceanic Systems
colors:
  surface: '#f9f9ff'
  surface-dim: '#cfdaf2'
  surface-bright: '#f9f9ff'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f0f3ff'
  surface-container: '#e7eeff'
  surface-container-high: '#dee8ff'
  surface-container-highest: '#d8e3fb'
  on-surface: '#111c2d'
  on-surface-variant: '#3f484f'
  inverse-surface: '#263143'
  inverse-on-surface: '#ecf1ff'
  outline: '#707880'
  outline-variant: '#bfc7d1'
  surface-tint: '#006493'
  primary: '#00618e'
  on-primary: '#ffffff'
  primary-container: '#007bb3'
  on-primary-container: '#fbfbff'
  inverse-primary: '#8ccdff'
  secondary: '#34647a'
  on-secondary: '#ffffff'
  secondary-container: '#b4e4fe'
  on-secondary-container: '#37667d'
  tertiary: '#006286'
  on-tertiary: '#ffffff'
  tertiary-container: '#007ca9'
  on-tertiary-container: '#f9fbff'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#cae6ff'
  primary-fixed-dim: '#8ccdff'
  on-primary-fixed: '#001e30'
  on-primary-fixed-variant: '#004b70'
  secondary-fixed: '#c0e8ff'
  secondary-fixed-dim: '#9ecde7'
  on-secondary-fixed: '#001e2b'
  on-secondary-fixed-variant: '#184c62'
  tertiary-fixed: '#c4e7ff'
  tertiary-fixed-dim: '#7dd0ff'
  on-tertiary-fixed: '#001e2d'
  on-tertiary-fixed-variant: '#004c6a'
  background: '#f9f9ff'
  on-background: '#111c2d'
  surface-variant: '#d8e3fb'
  surface-canvas: '#F8FAFC'
  surface-card: '#FFFFFF'
  surface-subtle: '#F1F5F9'
  border-subtle: '#E2E8F0'
  border-strong: '#CBD5E1'
  text-muted: '#64748B'
  brand-mid-dark: '#005B80'
  brand-deep: '#00283C'
  status-success: '#059669'
  status-warning: '#D97706'
  status-danger: '#E11D48'
  status-info: '#00A1DA'
typography:
  display-lg:
    fontFamily: Plus Jakarta Sans
    fontSize: 3.5rem
    fontWeight: '700'
    lineHeight: 4rem
    letterSpacing: -0.025em
  headline-lg:
    fontFamily: Plus Jakarta Sans
    fontSize: 2rem
    fontWeight: '700'
    lineHeight: 2.5rem
    letterSpacing: -0.02em
  headline-lg-mobile:
    fontFamily: Plus Jakarta Sans
    fontSize: 1.625rem
    fontWeight: '700'
    lineHeight: 2.125rem
    letterSpacing: -0.015em
  headline-md:
    fontFamily: Plus Jakarta Sans
    fontSize: 1.5rem
    fontWeight: '600'
    lineHeight: 2rem
    letterSpacing: -0.015em
  headline-sm:
    fontFamily: Plus Jakarta Sans
    fontSize: 1.25rem
    fontWeight: '600'
    lineHeight: 1.75rem
    letterSpacing: -0.01em
  title-md:
    fontFamily: Plus Jakarta Sans
    fontSize: 1rem
    fontWeight: '600'
    lineHeight: 1.5rem
    letterSpacing: -0.005em
  body-lg:
    fontFamily: Inter
    fontSize: 1.125rem
    fontWeight: '400'
    lineHeight: 1.75rem
  body-md:
    fontFamily: Inter
    fontSize: 0.875rem
    fontWeight: '400'
    lineHeight: 1.375rem
  body-sm:
    fontFamily: Inter
    fontSize: 0.75rem
    fontWeight: '400'
    lineHeight: 1.125rem
  label-md:
    fontFamily: Inter
    fontSize: 0.875rem
    fontWeight: '500'
    lineHeight: 1.25rem
    letterSpacing: 0.01em
  label-sm:
    fontFamily: Inter
    fontSize: 0.75rem
    fontWeight: '600'
    lineHeight: 1rem
    letterSpacing: 0.02em
  code-metric:
    fontFamily: Inter
    fontSize: 0.875rem
    fontWeight: '600'
    lineHeight: 1.25rem
    letterSpacing: -0.01em
rounded:
  sm: 0.125rem
  DEFAULT: 0.25rem
  md: 0.375rem
  lg: 0.5rem
  xl: 0.75rem
  full: 9999px
spacing:
  gutter: 1.25rem
  gutter-mobile: 0.75rem
  margin: 2rem
  margin-mobile: 1rem
  space-xs: 0.25rem
  space-sm: 0.5rem
  space-md: 1rem
  space-lg: 1.5rem
  space-xl: 2.5rem
---

## Brand & Style

This design system embodies an authoritative, precision-engineered aesthetic tailored for enterprise-grade B2B telemetry, weather monitoring, and industrial systems. Rooted in technical reliability and clarity, the interface projects calm confidence, uncompromising operational efficiency, and deep architectural integrity.

The visual style merges **Corporate / Modern** infrastructure with high-density technical minimalism. Interfaces are crisp, high-signal, and disciplined, avoiding decorative clutter in favor of scannable telemetry, subtle slate borders, rich deep-ocean accents, and clean surface hierarchies. Surfaces prioritize data density with razor-sharp alignment, balanced contrast, and ergonomic readability under mission-critical monitoring scenarios.

## Colors

The color architecture is built around a structured tonal gradient of deep ocean and cyan blues derived from the core telemetry branding:

- **Primary (`#007BB3`)**: The core interactive foundation. Used for key callouts, active navigation indicators, and primary action states.
- **Secondary (`#003E53`)**: A grounded, authoritative deep blue-slate. Anchors deep brand accents, telemetry card headers, active table row indicators, and high-emphasis toggles.
- **Tertiary (`#00A1DA`)**: The high-visibility cyan signal. Reserved for live status pulses, chart telemetry highlights, critical focus rings, and accent badges.
- **Neutral (`#1E293B`)**: Dark slate/charcoal for uncompromising typographical contrast, high-fidelity headers, and prominent metric values.

Backgrounds leverage a sterile light slate canvas (`#F8FAFC`) with crisp, pure white cards (`#FFFFFF`). Borders rely on subtle slate dividers (`#E2E8F0`) that frame tabular arrays and telemetry monitors without visual exhaustion.

## Typography

The typography couples the structured, humanist geometry of **Plus Jakarta Sans** for headlines and structural markers with the utilitarian legibility of **Inter** for dense dashboards, tabular data, and telemetry readouts.

- **Headlines & KPI Displays**: Rendered in Plus Jakarta Sans with subtle negative tracking to preserve structural tightness at scale.
- **Data & Tables**: Set in Inter utilizing tabular numbers (`font-variant-numeric: tabular-nums`) to guarantee strict numerical vertical alignment across weather metrics, coordinates, and telemetry readings.
- **Labels & Microcopy**: Formatted in Inter Medium and SemiBold with slight positive tracking to ensure rapid scanning in high-density cockpits.

## Layout & Spacing

The layout is architected around a flexible 12-column grid system tuned for data dashboards, telemetry feeds, and command panels:

- **Desktop (≥ 1280px)**: 12 columns, 1.25rem (`gutter`) column separation, 2rem (`margin`) canvas inset. Fixed collapsable sidebar navigation (240px wide).
- **Tablet (768px - 1279px)**: 8 columns, 1rem gutter, 1.5rem margin. Fluid multi-column telemetry cards reflow into 2-column modules.
- **Mobile (< 768px)**: 4 columns, 0.75rem (`gutter-mobile`), 1rem (`margin-mobile`). Dense tables convert into stacked card summaries; search filters collapse into flyout drawer menus.

Vertical rhythms respect an exact 4px/8px micro-grid. Metric rows and table cells utilize tight internal padding (`space-sm` to `space-md`) to ensure critical telemetry fits within the viewport fold without scrolling.

## Elevation & Depth

Visual depth is achieved through **low-contrast outlines** paired with subtle, ambient, multi-layered shadows. Heavy drop shadows and glass blurs are avoided to maintain maximum computational clarity:

- **Level 0 (Canvas Base)**: `#F8FAFC` flat surface.
- **Level 1 (Cards, Tables, Panels)**: `#FFFFFF` surface with a continuous 1px stroke of `border-subtle` (`#E2E8F0`) and an ambient shadow: `0 1px 3px 0 rgba(15, 23, 42, 0.04), 0 1px 2px -1px rgba(15, 23, 42, 0.03)`.
- **Level 2 (Hovered Cards & Filter Menus)**: `#FFFFFF` surface with `border-strong` (`#CBD5E1`) and a lifted shadow: `0 4px 6px -1px rgba(15, 23, 42, 0.06), 0 2px 4px -2px rgba(15, 23, 42, 0.04)`.
- **Level 3 (Modals, Command Palettes & Flyouts)**: `#FFFFFF` surface with `0 12px 24px -4px rgba(15, 23, 42, 0.1), 0 4px 6px -2px rgba(15, 23, 42, 0.05)`.
- **Active Telemetry Focus**: Rather than excessive elevation, active or alert components receive a distinct 2px border or ring tinted with `#00A1DA` (Tertiary).

## Shapes

The design system implements a **Soft (Level 1)** curvature discipline. Enterprise dashboards demand sharp geometric efficiency that maximizes internal screen space and preserves table column margins:

- **Inputs, Buttons, Cells & Table Components**: `0.25rem` (4px) border radius for compact, crisp ergonomics.
- **Cards, Monitoring Panels & Modal Shells**: `0.5rem` (8px) border radius (`rounded-lg`), providing mild softening without feeling consumer-casual.
- **Data Visualization Chips, Tags & Badges**: Fully rounded (`9999px` / pill) to establish contrast against rectangular dashboard modules.

## Components

### Buttons
- **Primary**: Solid `#007BB3` background, `#FFFFFF` text, `0.25rem` radius. On hover: `#005B80`. On active: `#003E53`. Subtle inner glow highlight.
- **Secondary**: `#FFFFFF` background with 1px border `#E2E8F0`, `#1E293B` text. On hover: `#F1F5F9` surface with `#CBD5E1` border.
- **Tertiary / Ghost**: Transparent background, `#007BB3` text. On hover: `#F1F5F9` background.
- **Sizes**: Compact (32px height for high-density action rows), Standard (38px height).

### Form Inputs & Search Filters
- **Text Inputs & Search**: Crisp white surface, 1px `#E2E8F0` border, `0.25rem` radius, `0.875rem` font size. Left-aligned search lens icon in `#64748B`. Focused state: 1px border `#007BB3` with a 3px ring of `#00A1DA` at 20% opacity.
- **Filter Dropdowns**: Split-chip styling displaying filter descriptor and active count badge (e.g., `Sensor: Active (4)`).

### Chips & Telemetry Tags
- **Status Chips**: Pill-shaped (`rounded-full`), `space-xs` vertical and `space-sm` horizontal padding. Font weight 600, uppercase `0.75rem`.
  - *Normal / Online*: Background `#ECFDF5`, text `#065F46`, dot indicator `#10B981`.
  - *Warning / Threshold*: Background `#FFFBEB`, text `#92400E`, dot indicator `#F59E0B`.
  - *Critical / Storm*: Background `#FFF1F2`, text `#9F1239`, dot indicator `#F43F5E`.
  - *Telemetry Stream*: Background `#F0F9FF`, text `#0369A1`, dot indicator `#00A1DA`.

### Data Tables (High-Density)
- **Header**: `#F8FAFC` background, 1px border bottom `#E2E8F0`. Uppercase `0.75rem` text with `letterSpacing: 0.05em`, color `#64748B`. Height: 36px.
- **Rows**: `#FFFFFF` surface, alternating row tint option `#FAFAFA`. Height: 40px (compact) or 48px (standard). Border bottom 1px `#F1F5F9`. Hover state: `#F1F5F9`.
- **Numerical Alignment**: Right-aligned, `tabular-nums` formatting for weather telemetry (temperature, barometric pressure, wind velocity, humidity).

### Cards & Metric Tiles
- **Weather / Metric Tiles**: White background, 1px `#E2E8F0` border, `space-md` internal padding. Features a discrete sub-header with metric label, prominent `headline-md` current value in `#1E293B`, and an inline Sparkline or delta badge (+2.4% vs benchmark).

### Selection Controls
- **Checkboxes & Radios**: 16px × 16px with 1px `#CBD5E1` border. When selected: `#007BB3` background with a crisp white check or center pip. Focus ring in `#00A1DA`.