<?php

declare(strict_types=1);

/*
 * The app's own stylesheets and scripts, in load order (A216). No build step (D-004): every file is linked
 * as it is. core/ = the framework (docs/design/FRAMEWORK.md), modules/ = one file per module.
 * CSS order matters (later files win on equal specificity); public/design/styleguide.html links the same list.
 * JS: core/bus.js first (window.DocHamster), core/start.js after the core setups; everything loads in <head>,
 * so new code waits for DOMContentLoaded or uses DocHamster.on('window:open', …).
 */
return [
    'css' => [
        'core/base', 'core/layout', 'core/forms', 'core/components', 'core/buttons', 'core/menus', 'core/lists',
        'core/columns', 'core/windows',
        'modules/documents', 'modules/notes', 'modules/text-editor', 'modules/ocr', 'modules/log', 'modules/updates',
        'modules/palette', 'modules/pages', 'modules/properties', 'modules/ocr-text', 'modules/girocode', 'core/layout-debug',
    ],
    'js' => [
        'core/bus', 'core/error-report', 'core/menu', 'core/dropdowns', 'core/card-fold', 'core/button-choice', 'modules/documents-filter', 'core/sortable', 'core/tabs',
        'core/tooltips', 'core/dialogs', 'core/windows', 'core/window-search', 'core/columns', 'core/scroll-hint',
        'core/badges', 'modules/notes', 'modules/tags-search', 'core/toasts', 'core/selection-bar', 'core/ajax-forms',
        'modules/updates-preview-pick', 'core/color-field', 'modules/folder-import', 'core/start',
        'modules/document-viewer', 'modules/ocr-text', 'modules/palette', 'modules/ocr-browser', 'modules/documents', 'modules/documents-rows', 'modules/documents-forms',
        'modules/documents-rename', 'modules/documents-trash', 'modules/documents-select', 'modules/documents-dnd', 'modules/documents-upload', 'modules/documents-search',
        'modules/updates',
        'modules/text-editor', 'core/edit-in-place', 'modules/ocr-progress', 'modules/ocr-live', 'modules/note-options',
        'modules/properties', 'modules/girocode', 'modules/bank', 'modules/inbox', 'core/window-edge', 'core/top-layer', 'core/layout-debug',
        'core/pwa', 'modules/share',
    ],
];
