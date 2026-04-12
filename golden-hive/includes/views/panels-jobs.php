<?php
/**
 * Jobs panel — unified scheduled-jobs management.
 *
 * Centralized tab replacing the legacy Scheduler + Run Log tabs.
 * Two sub-views ("list" and "log") toggled client-side via GH.jobsShow().
 */
defined( 'ABSPATH' ) || exit;
?>

<!-- ═══ JOBS ═══ -->
<div class="panel" id="panel-jobs" style="position:relative">
    <div class="toolbar">
        <button class="btn btn-primary" onclick="GH.jobsNew()">+ Nuovo job</button>
        <div class="filter-sep"></div>
        <button class="btn btn-ghost" id="btn-jobs-view-list" onclick="GH.jobsShow('list')" style="border-color:var(--acc)">Jobs</button>
        <button class="btn btn-ghost" id="btn-jobs-view-log"  onclick="GH.jobsShow('log')">Run Log</button>
        <div class="filter-sep"></div>
        <button class="btn btn-ghost" onclick="GH.jobsReload()">Aggiorna</button>
        <button class="btn btn-ghost" id="btn-jobs-clear-log" onclick="GH.jobsClearLog()" style="color:var(--red);display:none">Svuota log</button>
    </div>

    <!-- List view -->
    <div id="jobs-list-view">
        <div class="preview-wrap" id="jobs-list-area">
            <div class="empty-state">
                <div class="empty-icon">&#9202;</div>
                <div class="empty-text">Nessun job schedulato.<br>Crea un job per eseguire operazioni periodiche via wp-cron.</div>
            </div>
        </div>
    </div>

    <!-- Log view -->
    <div id="jobs-log-view" style="display:none">
        <div class="preview-wrap" id="jobs-log-area">
            <div class="empty-state"><div class="empty-icon">&#9776;</div><div class="empty-text">Nessun run registrato</div></div>
        </div>
    </div>

    <!-- Editor (modal-ish inline section) -->
    <div id="jobs-editor" style="display:none;padding:16px;border-top:1px solid var(--b1);margin-top:12px;background:var(--s2)">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
            <div style="font-family:var(--mono);font-size:12px;font-weight:500" id="jobs-editor-title">Nuovo job</div>
            <div style="display:flex;gap:4px">
                <button class="btn btn-ghost" id="jobs-edit-mode-form" onclick="GH.jobsSetEditMode('form')"  style="border-color:var(--acc)">Form</button>
                <button class="btn btn-ghost" id="jobs-edit-mode-code" onclick="GH.jobsSetEditMode('code')">Code (JSON)</button>
            </div>
        </div>

        <!-- Form mode -->
        <div id="jobs-edit-form">
            <div class="config-form">
                <div class="cfg-row">
                    <span class="cfg-label">Label</span>
                    <input class="cfg-input" id="jobs-f-label" placeholder="Es: Import giornaliero StockFirmati" />
                </div>
                <div class="cfg-row">
                    <span class="cfg-label">Kind</span>
                    <select class="cfg-select" id="jobs-f-kind" onchange="GH.jobsOnKindChange()" style="flex:1"></select>
                </div>
                <div class="cfg-row">
                    <span class="cfg-label">Cron expression</span>
                    <input class="cfg-input" id="jobs-f-cron" placeholder="* * * * *" style="font-family:var(--mono)" oninput="GH.jobsPreviewCron()" />
                </div>
                <div class="cfg-row" style="align-items:flex-start">
                    <span class="cfg-label">Simple</span>
                    <div style="display:flex;gap:6px;align-items:center;flex:1">
                        <span style="font-family:var(--mono);font-size:11px;color:var(--dim)">Ogni</span>
                        <input class="cfg-input" id="jobs-f-every" type="number" min="1" value="1" style="width:70px" />
                        <select class="cfg-select" id="jobs-f-unit">
                            <option value="minute">minuti</option>
                            <option value="hour" selected>ore</option>
                            <option value="day">giorni</option>
                            <option value="week">settimane</option>
                        </select>
                        <button class="btn btn-ghost" onclick="GH.jobsApplySimple()">→ Genera espressione</button>
                    </div>
                </div>
                <div class="cfg-row">
                    <span class="cfg-label">Prossime</span>
                    <div id="jobs-f-preview" style="flex:1;font-family:var(--mono);font-size:11px;color:var(--dim)">—</div>
                </div>
                <div class="cfg-row">
                    <span class="cfg-label">Runtime</span>
                    <div style="display:flex;gap:6px;align-items:center;flex:1">
                        <span style="font-family:var(--mono);font-size:11px;color:var(--dim)">max</span>
                        <input class="cfg-input" id="jobs-f-max-runtime" type="number" value="3600" style="width:90px" />
                        <span style="font-family:var(--mono);font-size:11px;color:var(--dim)">s &nbsp; tick</span>
                        <input class="cfg-input" id="jobs-f-tick-budget" type="number" value="25" style="width:70px" />
                        <span style="font-family:var(--mono);font-size:11px;color:var(--dim)">s</span>
                    </div>
                </div>
                <div class="cfg-row">
                    <span class="cfg-label">Abilitato</span>
                    <label style="font-family:var(--mono);font-size:11px;color:var(--dim);display:flex;align-items:center;gap:6px">
                        <input type="checkbox" id="jobs-f-enabled" checked /> esegui secondo la schedulazione
                    </label>
                </div>
                <div class="cfg-row" style="align-items:flex-start">
                    <span class="cfg-label">Params</span>
                    <div id="jobs-f-params" style="flex:1;display:flex;flex-direction:column;gap:6px"></div>
                </div>
            </div>
        </div>

        <!-- Code mode -->
        <div id="jobs-edit-code" style="display:none">
            <div style="font-family:var(--mono);font-size:11px;color:var(--dim);margin-bottom:6px">
                Edita direttamente il record JSON del job. Campi validi: label, kind, cron, enabled, max_runtime, tick_budget, params.
            </div>
            <textarea id="jobs-f-json" rows="18" style="width:100%;font-family:var(--mono);font-size:12px;background:var(--s3);color:var(--txt);border:1px solid var(--b1);border-radius:4px;padding:10px;resize:vertical"></textarea>
        </div>

        <div class="cfg-row" style="gap:8px;margin-top:12px">
            <button class="btn btn-primary" onclick="GH.jobsSave()"><span class="spin" id="jobs-save-spin" style="display:none"></span> Salva</button>
            <button class="btn btn-ghost" onclick="GH.jobsCancelEdit()">Annulla</button>
        </div>
    </div>
</div>
