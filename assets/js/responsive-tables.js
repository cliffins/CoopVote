/* Preserve accessible headings and keyboard-scrollable table regions for dynamic rows. */
(() => {
  'use strict';
  const selector = '.cv-data-table, .position-table, .officer-table';
  function prepare(table) {
    const headers = [...(table.tHead?.rows[0]?.cells || [])];
    if (!headers.length) return;
    table.classList.add('cv-responsive-table');
    table.setAttribute('role', 'table');
    const region = table.parentElement;
    region.classList.add('cv-table-container');
    region.tabIndex = 0;
    region.setAttribute('role', 'region');
    region.setAttribute('aria-label', 'Data table. Scroll horizontally to view all columns.');
    headers.forEach(header => {
      header.setAttribute('scope', 'col');
      header.setAttribute('role', 'columnheader');
      const checkbox = header.querySelector('input[type="checkbox"]');
      if (checkbox) {
        header.classList.add('cv-select-heading');
        checkbox.setAttribute('aria-label', 'Select all voters on this page');
      }
    });
    for (const section of [table.tHead, ...table.tBodies]) {
      if (!section) continue;
      section.setAttribute('role', 'rowgroup');
      for (const row of section.rows) row.setAttribute('role', 'row');
    }
    for (const body of table.tBodies) for (const row of body.rows) {
      let column = 0;
      for (const cell of row.cells) {
        cell.setAttribute('role', 'cell');
        if (cell.colSpan === 1) {
          cell.dataset.label = headers[column]?.textContent.trim() || 'Select';
          const checkbox = cell.querySelector('input[type="checkbox"]');
          if (checkbox && !checkbox.hasAttribute('aria-label')) checkbox.setAttribute('aria-label', 'Select this voter');
        } else delete cell.dataset.label;
        column += cell.colSpan;
      }
    }
  }
  function start() {
    document.querySelectorAll(selector).forEach(prepare);
    // Observe row replacements and new tables, not our own attribute updates.
    new MutationObserver(records => {
      const tables = new Set();
      for (const record of records) {
        const parent = record.target.nodeType === 1 ? record.target : record.target.parentElement;
        const table = parent?.closest(selector);
        if (table) tables.add(table);
        for (const node of record.addedNodes) if (node.nodeType === 1) {
          if (node.matches(selector)) tables.add(node);
          node.querySelectorAll(selector).forEach(table => tables.add(table));
        }
      }
      tables.forEach(prepare);
    }).observe(document.body, {childList:true, subtree:true});
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once:true});
  else start();
})();
