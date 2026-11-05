<style>
@page { size: A4 landscape; margin: 12mm; }
thead { display: table-header-group; }
tfoot { display: table-row-group; }
tr { break-inside: avoid; page-break-inside: avoid; }
th, td { overflow-wrap: anywhere; word-wrap: break-word; vertical-align: top; }
h1,h2,h3 { break-after: avoid; page-break-after: avoid; }
@media print {
  button, nav, .toolbar, .actions, .print { display: none !important; }
  body { margin: 0 !important; padding: 0 !important; background: white !important; max-width: none !important; }
  main, .sheet { max-width: none !important; border: 0 !important; box-shadow: none !important; padding: 0 !important; }
  .scroll, .table-wrap { overflow: visible !important; }
  table { width: 100%; table-layout: fixed; }
  th,td { font-size: 9px; }
}
</style>
