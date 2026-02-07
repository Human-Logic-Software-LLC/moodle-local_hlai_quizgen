# Step 3 Design Improvements Guide

## Overview

This document outlines the visual design issues with the current Step 3 (Define Question Parameters) page and provides recommendations for making it more visually appealing and consistent with modern UI standards.

---

## Current Issues

### 1. Too Many Colors (No Harmony)

| Element | Current Color | Problem |
|---------|---------------|---------|
| Topics banner | Blue | OK |
| "10" input | Purple | Doesn't match anything |
| Total badge | Orange/Red | Too harsh |
| Difficulty selected | Gray/Blue | Too subtle |
| Sliders | Green | OK but inconsistent |
| Section icons | Multiple colors | Visual chaos |

**Result:** The page feels like a rainbow with no cohesive color story.

---

### 2. The Big Purple "10" Input Box

- Looks completely out of place
- Too heavy and bold
- Doesn't match the design language of anything else
- Draws too much attention to a simple input

---

### 3. Question Type Rows Look Flat

- Just text floating on white background
- No visual separation between rows
- The small icons are nice but get visually lost
- Number inputs look disconnected from their labels
- No hover states or interactivity feedback

---

### 4. Difficulty Toggle Buttons

- Selected state ("Balanced") is too subtle
- Hard to tell which option is currently active
- Buttons look like plain text links
- No visual affordance that they're clickable

---

### 5. Bloom's Taxonomy Sliders

- Green filled portion is good
- Gray unfilled track looks incomplete/broken
- Labels have trailing dashes ("Remember-") - looks like a bug
- Percentage badges are nice but could be better aligned
- No description text visible for each level

---

### 6. No Visual Hierarchy

- Everything has the same visual weight
- Hard to scan the page quickly
- Sections blend into each other
- User doesn't know where to focus

---

## Recommended Solutions

### Color Strategy

```
PRIMARY RULE: Pick ONE accent color and use it consistently.

Recommended: Iksha Blue (#3B82F6)
- Selected states
- Primary buttons
- Focus rings
- Active indicators

SEMANTIC COLORS (use only for meaning):
- Green (#10B981)  → Success, correct, complete
- Red (#EF4444)    → Error, danger, rejected
- Orange (#F59E0B) → Warning (use sparingly!)

NEUTRAL COLORS:
- #F8FAFC → Page background
- #F1F5F9 → Section backgrounds
- #E2E8F0 → Borders, dividers
- #1E293B → Headings
- #64748B → Body text, descriptions
```

---

### Recommended Color Palette

```css
:root {
    /* Primary */
    --color-primary: #3B82F6;
    --color-primary-light: #EFF6FF;
    --color-primary-dark: #1D4ED8;

    /* Semantic */
    --color-success: #10B981;
    --color-success-light: #ECFDF5;
    --color-warning: #F59E0B;
    --color-warning-light: #FFFBEB;
    --color-danger: #EF4444;
    --color-danger-light: #FEF2F2;

    /* Neutrals */
    --color-gray-50: #F8FAFC;
    --color-gray-100: #F1F5F9;
    --color-gray-200: #E2E8F0;
    --color-gray-300: #CBD5E1;
    --color-gray-400: #94A3B8;
    --color-gray-500: #64748B;
    --color-gray-600: #475569;
    --color-gray-700: #334155;
    --color-gray-800: #1E293B;
    --color-gray-900: #0F172A;
}
```

---

## Component Redesigns

### 1. Total Number of Questions Input

**Current:**
```
┌─────────────────────┐
│  Purple Background  │
│        10           │
└─────────────────────┘
```

**Recommended:**
```
┌─────────────────────┐
│  White Background   │
│  Blue border focus  │
│        10           │
└─────────────────────┘
```

**CSS Changes:**
```css
#total-questions {
    background: white;
    border: 2px solid var(--color-gray-200);
    border-radius: 8px;
    font-size: 2rem;
    font-weight: 700;
    text-align: center;
    width: 100px;
    padding: 0.75rem;
    transition: border-color 0.2s, box-shadow 0.2s;
}

#total-questions:focus {
    border-color: var(--color-primary);
    box-shadow: 0 0 0 4px var(--color-primary-light);
    outline: none;
}
```

---

### 2. Question Type Rows

**Current:**
```
🔘 Multiple Choice                              0
✓✗ True/False                                   0
```

**Recommended:**
```
┌─────────────────────────────────────────────────────┐
│ 🔘 Multiple Choice                         [ 5 ]   │
│    Select one answer from multiple options         │
├─────────────────────────────────────────────────────┤
│ ✓✗ True/False                              [ 3 ]   │
│    Binary choice questions                         │
├─────────────────────────────────────────────────────┤
│ ✏️ Short Answer                            [ 2 ]   │
│    Brief text response                             │
├─────────────────────────────────────────────────────┤
│ 📄 Essay                                   [ 0 ]   │
│    Extended writing response                       │
├─────────────────────────────────────────────────────┤
│ 🎯 Scenario-based                          [ 0 ]   │
│    Case study analysis                             │
╞═════════════════════════════════════════════════════╡
│ TOTAL                                    10 / 10 ✓ │
└─────────────────────────────────────────────────────┘
```

**CSS Changes:**
```css
.question-type-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--color-gray-100);
    transition: background-color 0.15s;
}

.question-type-row:hover {
    background-color: var(--color-gray-50);
}

.question-type-row:last-of-type {
    border-bottom: none;
}

.question-type-info {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.question-type-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    background: var(--color-gray-100);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
}

.question-type-label {
    font-weight: 600;
    color: var(--color-gray-800);
}

.question-type-desc {
    font-size: 0.85rem;
    color: var(--color-gray-500);
}

.question-type-input {
    width: 70px;
    text-align: center;
    font-weight: 600;
    border: 1px solid var(--color-gray-300);
    border-radius: 6px;
    padding: 0.5rem;
}

/* Total Row */
.question-type-total {
    background: var(--color-gray-50);
    border-top: 2px solid var(--color-gray-200);
    font-weight: 700;
}

.total-badge {
    background: var(--color-primary);
    color: white;
    padding: 0.25rem 0.75rem;
    border-radius: 4px;
    font-weight: 600;
}

.total-badge.is-valid {
    background: var(--color-success);
}

.total-badge.is-invalid {
    background: var(--color-danger);
}
```

---

### 3. Difficulty Toggle Buttons

**Current:**
```
[Easy Only] [Balanced (Mix of Easy, Medium, Hard)] [Hard Only] [Custom Distribution]
             ↑ barely visible that this is selected
```

**Recommended:**
```
┌──────────┐  ╔═══════════════╗  ┌───────────┐  ┌─────────────────┐
│Easy Only │  ║   Balanced  ✓ ║  │ Hard Only │  │ Custom          │
└──────────┘  ╚═══════════════╝  └───────────┘  └─────────────────┘
              ↑ Bold border, filled background, checkmark
```

**CSS Changes:**
```css
.difficulty-toggle-group {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.difficulty-toggle {
    padding: 0.625rem 1.25rem;
    border: 2px solid var(--color-gray-200);
    border-radius: 8px;
    background: white;
    color: var(--color-gray-600);
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s;
}

.difficulty-toggle:hover {
    border-color: var(--color-primary);
    color: var(--color-primary);
}

.difficulty-toggle.is-selected {
    border-color: var(--color-primary);
    background: var(--color-primary-light);
    color: var(--color-primary-dark);
    font-weight: 600;
}

.difficulty-toggle.is-selected::after {
    content: " ✓";
}
```

---

### 4. Bloom's Taxonomy Sliders

**Current:**
```
Remember-                                              [20%]
████████████○─────────────────────────────────────────────
```

**Recommended:**
```
Remember                                               [20%]
Recall facts and basic concepts
━━━━━━━━━━━━●━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     ↑ Filled        ↑ Thumb         ↑ Unfilled (subtle)
```

**CSS Changes:**
```css
.blooms-slider-row {
    padding: 1rem 0;
    border-bottom: 1px solid var(--color-gray-100);
}

.blooms-slider-row:last-of-type {
    border-bottom: none;
}

.blooms-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
}

.blooms-label {
    font-weight: 600;
    color: var(--color-gray-800);
}

.blooms-description {
    font-size: 0.85rem;
    color: var(--color-gray-500);
    margin-bottom: 0.5rem;
}

.blooms-value {
    background: var(--color-primary);
    color: white;
    padding: 0.2rem 0.6rem;
    border-radius: 4px;
    font-size: 0.85rem;
    font-weight: 600;
    min-width: 50px;
    text-align: center;
}

/* Custom Range Slider */
input[type="range"] {
    -webkit-appearance: none;
    width: 100%;
    height: 6px;
    border-radius: 3px;
    background: linear-gradient(
        to right,
        var(--color-primary) 0%,
        var(--color-primary) var(--value, 20%),
        var(--color-gray-200) var(--value, 20%),
        var(--color-gray-200) 100%
    );
    cursor: pointer;
}

input[type="range"]::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: var(--color-primary);
    border: 3px solid white;
    box-shadow: 0 2px 6px rgba(59, 130, 246, 0.4);
    cursor: pointer;
    transition: transform 0.15s;
}

input[type="range"]::-webkit-slider-thumb:hover {
    transform: scale(1.15);
}

input[type="range"]::-moz-range-thumb {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: var(--color-primary);
    border: 3px solid white;
    box-shadow: 0 2px 6px rgba(59, 130, 246, 0.4);
    cursor: pointer;
}
```

---

## Overall Page Structure

### Recommended Layout

```
┌─────────────────────────────────────────────────────────────────┐
│  STEP INDICATOR (1-2-3-4-5)                                     │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  ⚙️ Define Question Parameters                          │   │
│  │  Configure question types, difficulty, and quality      │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  ┌─ INFO BANNER ───────────────────────────────────────────┐   │
│  │ ✅ 12 topics selected: Topic 1, Topic 2, Topic 3...     │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  ┌─ SECTION: Total Questions ──────────────────────────────┐   │
│  │                                                         │   │
│  │  📊 Total Number of Questions                           │   │
│  │  How many questions do you want to generate?            │   │
│  │                                                         │   │
│  │                    ┌─────────┐                          │   │
│  │                    │   10    │                          │   │
│  │                    └─────────┘                          │   │
│  │                                                         │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  ┌─ SECTION: Question Types ───────────────────────────────┐   │
│  │                                                         │   │
│  │  📝 Question Types                                      │   │
│  │  Specify how many questions of each type                │   │
│  │                                                         │   │
│  │  ┌─────────────────────────────────────────────────┐   │   │
│  │  │ 🔘 Multiple Choice                       [ 5 ]  │   │   │
│  │  ├─────────────────────────────────────────────────┤   │   │
│  │  │ ✓✗ True/False                            [ 3 ]  │   │   │
│  │  ├─────────────────────────────────────────────────┤   │   │
│  │  │ ✏️ Short Answer                          [ 2 ]  │   │   │
│  │  ├─────────────────────────────────────────────────┤   │   │
│  │  │ 📄 Essay                                 [ 0 ]  │   │   │
│  │  ├─────────────────────────────────────────────────┤   │   │
│  │  │ 🎯 Scenario-based                        [ 0 ]  │   │   │
│  │  ╞═════════════════════════════════════════════════╡   │   │
│  │  │ TOTAL                                10 / 10 ✓  │   │   │
│  │  └─────────────────────────────────────────────────┘   │   │
│  │                                                         │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  ┌─ SECTION: Difficulty ───────────────────────────────────┐   │
│  │                                                         │   │
│  │  🎯 Difficulty Distribution                             │   │
│  │  Choose the difficulty mix for your questions           │   │
│  │                                                         │   │
│  │  ┌────────┐ ╔════════════╗ ┌────────┐ ┌────────────┐   │   │
│  │  │  Easy  │ ║ Balanced ✓ ║ │  Hard  │ │   Custom   │   │   │
│  │  └────────┘ ╚════════════╝ └────────┘ └────────────┘   │   │
│  │                                                         │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  ┌─ SECTION: Bloom's Taxonomy ─────────────────────────────┐   │
│  │                                                         │   │
│  │  🧠 Bloom's Taxonomy Distribution                       │   │
│  │  Set the cognitive level distribution                   │   │
│  │                                                         │   │
│  │  Remember                                       [20%]   │   │
│  │  Recall facts and basic concepts                        │   │
│  │  ━━━━━━━━━━●━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━   │   │
│  │                                                         │   │
│  │  Understand                                     [25%]   │   │
│  │  Explain ideas or concepts                              │   │
│  │  ━━━━━━━━━━━━━━●━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━   │   │
│  │                                                         │   │
│  │  ... (Apply, Analyze, Evaluate, Create)                 │   │
│  │                                                         │   │
│  │  ┌─────────────────────────────────────────────────┐   │   │
│  │  │ Total: [100%] ✓                                 │   │   │
│  │  └─────────────────────────────────────────────────┘   │   │
│  │                                                         │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  [← Previous]                      [🚀 Generate Questions] │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## Quick Wins Summary

| Priority | Change | Impact | Effort |
|----------|--------|--------|--------|
| 🔴 High | Remove purple from "10" input | High | Low |
| 🔴 High | Make difficulty selection more visible | High | Low |
| 🔴 High | Fix trailing dashes in Bloom's labels | High | Low |
| 🟡 Medium | Add row hover states to question types | Medium | Low |
| 🟡 Medium | Add subtle dividers between sections | Medium | Low |
| 🟡 Medium | Standardize all icons to same style | Medium | Medium |
| 🟢 Low | Add description text under Bloom's labels | Low | Low |
| 🟢 Low | Animate slider thumb on hover | Low | Low |

---

## Files to Modify

| File | Changes Required |
|------|------------------|
| `styles-bulma.css` | Update CSS for all components above |
| `wizard.php` | Minor HTML class adjustments (optional) |

---

## Production Considerations

### Development vs Production CSS

```
/local/hlai_quizgen/
├── css/
│   ├── styles-bulma.css      ← Development (readable)
│   └── styles-bulma.min.css  ← Production (minified)
```

### Environment-Based Loading

```php
// In wizard.php or lib.php
$isdev = debugging() || get_config('core', 'debug') > 0;

if ($isdev) {
    $PAGE->requires->css('/local/hlai_quizgen/css/styles-bulma.css');
} else {
    $PAGE->requires->css('/local/hlai_quizgen/css/styles-bulma.min.css');
}
```

### Minification Tools

- **npm:** `npx cssnano styles-bulma.css styles-bulma.min.css`
- **Online:** [cssnano.co](https://cssnano.co) or [cssminifier.com](https://cssminifier.com)
- **VS Code:** "Minify" extension

---

## Next Steps

1. Review this document with stakeholders
2. Prioritize which changes to implement first
3. Implement CSS changes in `styles-bulma.css`
4. Test across different browsers and screen sizes
5. Create minified version for production
6. Deploy and gather feedback

---

*Document created: February 2026*
*For: HLAI Quiz Generator Plugin - Step 3 UI Improvements*
