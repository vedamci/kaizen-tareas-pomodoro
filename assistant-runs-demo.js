// DEMO · datos ficticios. Synthetic assistant runs used until a verified API
// contract exists. No real tasks, people, notes or workspaces; never fetched.
(function (root) {
  'use strict';
  function demoRuns(now = Date.now()) {
    const at = minutesAgo => new Date(now - minutesAgo * 60000).toISOString();
    const ev = (id, type, minutesAgo, summary = '') => ({ id, type, at: at(minutesAgo), actor: type === 'delegated' ? 'Persona demo' : 'Luna (demo)', summary });
    const run = (id, title, status, delegatedAgo, events, extra = {}) => ({ kind: 'assistant_run', id, workspace_id: 'demo', assistant: 'Luna (demo)', title, status, delegated_at: delegatedAgo === null ? '' : at(delegatedAgo), events, ...extra });
    return [
      run('demo-run-1', 'Resumen de un artículo ficticio (demo)', 'in_progress', 190, [
        ev('d1-1', 'delegated', 190, 'Delegación de ejemplo.'),
        ev('d1-2', 'status_changed', 180, 'En curso.'),
        ev('d1-3', 'work_started', 175),
        ev('d1-4', 'work_paused', 130),
        ev('d1-5', 'progress_note', 128, 'Borrador de tres párrafos listo (demo).'),
        ev('d1-6', 'next_step_set', 127, 'Revisar el tono del resumen de ejemplo.'),
        ev('d1-7', 'work_started', 40)
      ], { brief: 'Resumir un texto inventado en tres párrafos para revisar el diseño.' }),
      run('demo-run-2', 'Comparar dos plantillas de ejemplo (demo)', 'blocked', 1500, [
        ev('d2-1', 'delegated', 1500, 'Delegación de ejemplo.'),
        ev('d2-2', 'progress_note', 1380, 'Primera plantilla revisada (demo).'),
        ev('d2-3', 'blocker_set', 1320, 'Falta la segunda plantilla ficticia.'),
        ev('d2-4', 'next_step_set', 1319, 'Esperar a que la persona demo comparta la plantilla.'),
        ev('d2-5', 'status_changed', 1318, 'Bloqueada.')
      ], { brief: 'Comparar dos plantillas genéricas y señalar diferencias.' }),
      run('demo-run-3', 'Lista de verificación de ejemplo (demo)', 'completed', 4400, [
        ev('d3-1', 'delegated', 4400, 'Delegación de ejemplo.'),
        ev('d3-2', 'work_started', 4300),
        ev('d3-3', 'work_paused', 4240),
        ev('d3-4', 'work_paused', 4200),
        ev('d3-5', 'deliverable_added', 4190, 'Lista de verificación ficticia (demo).'),
        ev('d3-6', 'completed', 4180, 'Entregada.')
      ], { completed_at: at(4180) }),
      run('demo-run-4', 'Ordenar ideas de un taller ficticio (demo)', 'queued', 25, [
        ev('d4-1', 'delegated', 25, 'Delegación de ejemplo.')
      ]),
      run('demo-run-5', 'Borrador descartado de ejemplo (demo)', 'cancelled', 9000, [
        ev('d5-1', 'delegated', 9000, 'Delegación de ejemplo.'),
        ev('d5-2', 'progress_note', 8800, 'Esquema inicial (demo).'),
        ev('d5-3', 'cancelled', 8700, 'Cancelada por la persona demo.')
      ], { closed_at: at(8700) }),
      run('demo-run-6', 'Registro importado sin fecha de delegación (demo)', 'completed', null, [
        ev('d6-1', 'delegated', 3000, 'Delegación de ejemplo con fecha de origen ausente.'),
        ev('d6-2', 'deliverable_added', 2900, 'Nota de ejemplo (demo).'),
        ev('d6-3', 'completed', 2890, 'Entregada.')
      ], { completed_at: at(2890) })
    ];
  }
  const api = { demo: true, label: 'DEMO · datos ficticios', demoRuns };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.KaizenAssistantDemo = api;
})(typeof globalThis !== 'undefined' ? globalThis : this);
