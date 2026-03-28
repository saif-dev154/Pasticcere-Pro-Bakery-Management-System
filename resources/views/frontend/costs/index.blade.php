{{-- resources/views/frontend/costs/index.blade.php --}}
@extends('frontend.layouts.app')

@section('title', 'Tutti i Costi')

@section('content')
<div class="container py-5 px-md-5">

    {{-- Bulk Upload Cost Invoice --}}
    <div class="ing-card ing-card--upload mb-5">
        <div class="ing-card-header">
            <div class="ing-card-header-left">
                <span class="ing-card-header-icon">
                    <i class="bi bi-cloud-arrow-up-fill"></i>
                </span>
                <div>
                    <h5 class="ing-card-title">Carica Fattura Costi — Estrazione AI Automatica</h5>
                    <p class="ing-card-subtitle mb-0">Analisi OCR Google Vision + GPT-4o</p>
                </div>
            </div>
            <span class="ing-badge-ai">
                <i class="bi bi-stars me-1"></i>AI Powered
            </span>
        </div>

        <div class="ing-card-body">
            {{-- Drop Zone --}}
            <div id="costDropZone" class="ing-dropzone"
                 onclick="document.getElementById('invoiceFile').click()"
                 ondragover="event.preventDefault();this.classList.add('ing-dropzone--active');"
                 ondragleave="this.classList.remove('ing-dropzone--active');"
                 ondrop="handleCostDrop(event)">

                <div id="costDzContent" class="ing-dropzone-content">
                    <div class="ing-dropzone-icon">
                        <i class="bi bi-file-earmark-arrow-up"></i>
                    </div>
                    <p class="ing-dropzone-title">Clicca o trascina la fattura qui</p>
                    <p class="ing-dropzone-hint">JPG, PNG, WEBP, PDF — max 20 MB</p>
                    <div class="ing-dropzone-formats">
                        <span><i class="bi bi-file-image me-1"></i>Immagini</span>
                        <span><i class="bi bi-file-pdf me-1"></i>PDF</span>
                        <span><i class="bi bi-lightning-charge me-1"></i>Risultati in secondi</span>
                    </div>
                </div>

                <div id="costDzLoading" class="ing-dropzone-loading d-none">
                    <div class="ing-spinner">
                        <div class="ing-spinner-ring"></div>
                        <i class="bi bi-cpu-fill ing-spinner-icon"></i>
                    </div>
                    <p class="ing-dropzone-title mt-3">Elaborazione in corso...</p>
                    <p class="ing-dropzone-hint" id="costDzStep">Analisi OCR con Google Vision...</p>
                </div>
            </div>

            <input type="file" id="invoiceFile" accept=".jpg,.jpeg,.png,.webp,.pdf"
                   class="d-none" onchange="handleCostFileSelect(this)">

            <div id="extractStatus" class="ing-alert ing-alert--danger mt-3 d-none" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <span id="extractStatusMsg"></span>
            </div>

            {{-- Bulk Preview (shown after extraction) --}}
            <div id="bulkPreviewWrapper" class="mt-4 d-none">
                <hr>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="ing-label fw-semibold">Fornitore</label>
                        <input type="text" id="bulk_supplier_name" class="ing-input">
                    </div>
                    <div class="col-md-4">
                        <label class="ing-label fw-semibold">Codice fattura</label>
                        <input type="text" id="bulk_invoice_code" class="ing-input">
                    </div>
                    <div class="col-md-4">
                        <label class="ing-label fw-semibold">Data</label>
                        <input type="date" id="bulk_date" class="ing-input">
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0" id="bulkCostPreviewTable">
                        <thead>
                            <tr class="text-center">
                                <th>Descrizione</th>
                                <th style="min-width:150px;">Importo</th>
                                <th style="min-width:220px;">Categoria</th>
                                <th>Altra categoria / note</th>
                                <th style="min-width:120px;">Stato</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                    <div id="bulkSummaryText" class="text-muted"></div>
                    <button type="button" class="ing-btn ing-btn--confirm" id="saveBulkCostsBtn">
                        <i class="bi bi-save2 me-2"></i> Salva costi estratti
                    </button>
                </div>

                <div id="bulkSaveResult" class="mt-3"></div>
            </div>
        </div>
    </div>

    <!-- Aggiungi / Modifica Costo -->
    <div class="ing-card mb-5">
        <div class="ing-card-header">
            <div class="ing-card-header-left">
                <span class="ing-card-header-icon">
                    <i class="bi bi-{{ isset($cost) ? 'pencil-square' : 'plus-circle-fill' }}"></i>
                </span>
                <div>
                    <h5 class="ing-card-title">
                        {{ isset($cost) ? 'Modifica Costo' : 'Aggiungi Costo' }}
                    </h5>
                    <p class="ing-card-subtitle mb-0">
                        {{ isset($cost) ? 'Modifica i dati del costo selezionato' : 'Inserisci un nuovo costo manualmente' }}
                    </p>
                </div>
            </div>
        </div>

        <div class="ing-card-body">
            <form method="POST"
                  action="{{ isset($cost) ? route('costs.update', $cost) : route('costs.store') }}"
                  class="row g-3 needs-validation" novalidate>
                @csrf
                @isset($cost)
                    @method('PUT')
                @endisset

                <div class="col-md-6">
                    <label for="cost_identifier" class="ing-label">
                        <i class="bi bi-hash me-1"></i>Identificatore Costo
                        <small class="text-muted">(facoltativo)</small>
                    </label>
                    <input type="text"
                           id="cost_identifier"
                           name="cost_identifier"
                           class="ing-input"
                           value="{{ old('cost_identifier', $cost->cost_identifier ?? '') }}">
                </div>

                <div class="col-md-6">
                    <label for="supplier" class="ing-label">
                        <i class="bi bi-building me-1"></i>Fornitore <span class="ing-required">*</span>
                    </label>
                    <input type="text"
                           id="supplier"
                           name="supplier"
                           class="ing-input"
                           value="{{ old('supplier', $cost->supplier ?? '') }}"
                           required>
                    <div class="ing-feedback-invalid">Inserisci un fornitore.</div>
                </div>

                <div class="col-md-6">
                    <label for="amount" class="ing-label">
                        <i class="bi bi-currency-euro me-1"></i>Importo <span class="ing-required">*</span>
                    </label>
                    <div class="ing-input-group">
                        <span class="ing-input-addon">€</span>
                        <input type="number"
                               step="0.01"
                               id="amount"
                               name="amount"
                               class="ing-input ing-input--mid"
                               value="{{ old('amount', $cost->amount ?? '') }}"
                               required>
                        <div class="ing-feedback-invalid">Inserisci un importo valido.</div>
                    </div>
                </div>

                <div class="col-md-6">
                    <label for="due_date" class="ing-label">
                        <i class="bi bi-calendar3 me-1"></i>Data di scadenza <span class="ing-required">*</span>
                    </label>
                    <input type="date"
                           id="due_date"
                           name="due_date"
                           class="ing-input"
                           value="{{ old('due_date', $cost->due_date ?? '') }}"
                           required>
                    <div class="ing-feedback-invalid">Seleziona una data.</div>
                </div>

                <div class="col-md-6">
                    <label for="category_id" class="ing-label">
                        <i class="bi bi-tag me-1"></i>Categoria <span class="ing-required">*</span>
                    </label>
                    <select id="category_id"
                            name="category_id"
                            class="ing-input"
                            style="height:44px;"
                            required>
                        <option value="">Seleziona…</option>
                        @foreach ($categories as $c)
                            <option value="{{ $c->id }}"
                                {{ old('category_id', $cost->category_id ?? '') == $c->id ? 'selected' : '' }}>
                                {{ $c->name }}
                            </option>
                        @endforeach
                    </select>
                    <div class="ing-feedback-invalid">Seleziona una categoria.</div>
                </div>

                <div class="col-12 ing-form-actions">
                    @isset($cost)
                        <a href="{{ route('costs.index') }}" class="ing-btn ing-btn--secondary">
                            <i class="bi bi-x-lg me-2"></i>Annulla
                        </a>
                    @endisset
                    <button type="submit" class="ing-btn ing-btn--primary">
                        <i class="bi bi-save2 me-2"></i>
                        {{ isset($cost) ? 'Aggiorna Costo' : 'Salva Costo' }}
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Filtra per mese -->
    <div class="row g-2 align-items-end mb-4">
        <div class="col-auto">
            <label for="filterMonth" class="ing-label">Mostra mese</label>
            <input type="month"
                   id="filterMonth"
                   class="ing-input"
                   style="width:auto;"
                   value="{{ now()->format('Y-m') }}">
        </div>
    </div>

    <!-- Tabella Costi -->
    <div class="ing-card">
        <div class="ing-card-header">
            <div class="ing-card-header-left">
                <span class="ing-card-header-icon">
                    <i class="bi bi-table"></i>
                </span>
                <div>
                    <h5 class="ing-card-title">Tutti i Costi</h5>
                    <p class="ing-card-subtitle mb-0">{{ $costs->count() }} costi nel registro</p>
                </div>
            </div>
        </div>
        <div class="ing-card-body p-0">
            <div class="table-responsive">
                <table data-page-length="25" id="costTable"
                       class="ing-table">
                    <thead>
                        <tr>
                            <th class="sortable" style="width:20px;">Identificatore <span class="sort-indicator"></span></th>
                            <th class="sortable">Fornitore <span class="sort-indicator"></span></th>
                            <th class="sortable text-end">Importo <span class="sort-indicator"></span></th>
                            <th class="sortable">Scadenza <span class="sort-indicator"></span></th>
                            <th class="sortable">Categoria <span class="sort-indicator"></span></th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($costs as $item)
                            <tr>
                                <td>{{ $item->cost_identifier }}</td>
                                <td>{{ $item->supplier }}</td>
                                <td class="text-end" data-order="{{ $item->amount }}">
                                    <span class="ing-price-badge">
                                        €{{ number_format($item->amount, 2) }}
                                    </span>
                                </td>
                                <td data-order="{{ \Carbon\Carbon::parse($item->due_date)->format('Y-m-d') }}">
                                    <span class="ing-date-cell">
                                        <i class="bi bi-clock me-1"></i>
                                        {{ \Carbon\Carbon::parse($item->due_date)->format('d/m/Y') }}
                                    </span>
                                </td>
                                <td>
                                    @if($item->category)
                                        <span class="ing-alias-badge">{{ $item->category->name }}</span>
                                    @else
                                        <span class="ing-table-empty">—</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="ing-action-group">
                                        <a href="{{ route('costs.show', $item) }}"
                                           class="ing-action-btn ing-action-btn--view"
                                           title="Visualizza Costo">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="{{ route('costs.edit', $item) }}"
                                           class="ing-action-btn ing-action-btn--edit"
                                           title="Modifica Costo">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <form action="{{ route('costs.destroy', $item) }}"
                                              method="POST"
                                              class="d-inline"
                                              onsubmit="return confirm('Eliminare questo costo?');">
                                            @csrf
                                            @method('DELETE')
                                            <button class="ing-action-btn ing-action-btn--delete" title="Elimina Costo">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
@endsection

<style>
/* ── Variables ─────────────────────────────────────────── */
:root {
  --gold:       #e2ae76;
  --gold-light: #f5e6cf;
  --gold-dark:  #c9954d;
  --navy:       #041930;
  --navy-mid:   #093060;
  --navy-light: #0d4a8a;
  --bg:         #f7f8fc;
  --surface:    #ffffff;
  --border:     #e4e8f0;
  --text:       #1a2332;
  --text-muted: #6b7a99;
  --success:    #16a34a;
  --danger:     #dc2626;
  --shadow-sm:  0 1px 3px rgba(4,25,48,.07);
  --shadow-md:  0 4px 16px rgba(4,25,48,.10), 0 2px 6px rgba(4,25,48,.06);
  --shadow-lg:  0 10px 40px rgba(4,25,48,.14);
  --radius:     12px;
  --radius-sm:  8px;
  --radius-lg:  16px;
}

/* ── Cards ─────────────────────────────────────────────── */
.ing-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-md);
  overflow: hidden;
}
.ing-card--upload { border-top: 3px solid var(--gold); }

.ing-card-header {
  background: linear-gradient(135deg, var(--navy) 0%, var(--navy-mid) 100%);
  padding: 1.1rem 1.5rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: .75rem;
}
.ing-card-header-left { display: flex; align-items: center; gap: .875rem; }
.ing-card-header-icon {
  width: 40px; height: 40px;
  background: rgba(226,174,118,.18);
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  color: var(--gold); font-size: 1.2rem; flex-shrink: 0;
}
.ing-card-title  { font-size: 1rem; font-weight: 700; color: var(--gold); margin: 0; }
.ing-card-subtitle { font-size: .8rem; color: rgba(226,174,118,.65); }
.ing-card-body { padding: 1.5rem; }

.ing-badge-ai {
  background: rgba(226,174,118,.15);
  border: 1px solid rgba(226,174,118,.3);
  color: var(--gold);
  font-size: .75rem; font-weight: 600;
  padding: .35rem .8rem; border-radius: 20px; white-space: nowrap;
}

/* ── Drop Zone ─────────────────────────────────────────── */
.ing-dropzone {
  border: 2px dashed var(--gold);
  border-radius: var(--radius);
  background: #fffdf9;
  cursor: pointer;
  padding: 2.5rem 2rem;
  text-align: center;
  transition: all .2s ease;
  position: relative;
  overflow: hidden;
}
.ing-dropzone::before {
  content: '';
  position: absolute; inset: 0;
  background: radial-gradient(ellipse at top, rgba(226,174,118,.08) 0%, transparent 70%);
  pointer-events: none;
}
.ing-dropzone--active, .ing-dropzone:hover {
  background: #fdf3e3;
  border-color: var(--gold-dark);
  box-shadow: 0 0 0 4px rgba(226,174,118,.12);
}
.ing-dropzone-content, .ing-dropzone-loading { position: relative; }

.ing-dropzone-icon {
  width: 72px; height: 72px;
  background: linear-gradient(135deg, rgba(226,174,118,.15), rgba(226,174,118,.05));
  border-radius: 50%;
  margin: 0 auto .875rem;
  display: flex; align-items: center; justify-content: center;
  font-size: 2rem; color: var(--gold-dark);
}
.ing-dropzone-title { font-size: 1.1rem; font-weight: 700; color: var(--navy); margin-bottom: .35rem; }
.ing-dropzone-hint  { font-size: .85rem; color: var(--text-muted); margin-bottom: .875rem; }
.ing-dropzone-formats { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; }
.ing-dropzone-formats span {
  font-size: .78rem; color: var(--text-muted);
  background: white; border: 1px solid var(--border);
  padding: .25rem .65rem; border-radius: 20px;
}

/* ── Spinner ───────────────────────────────────────────── */
.ing-spinner {
  width: 64px; height: 64px; margin: 0 auto;
  position: relative; display: flex; align-items: center; justify-content: center;
}
.ing-spinner-ring {
  position: absolute; inset: 0;
  border: 3px solid rgba(226,174,118,.2);
  border-top-color: var(--gold);
  border-radius: 50%;
  animation: ing-spin 1s linear infinite;
}
.ing-spinner-icon { font-size: 1.5rem; color: var(--gold); }
@keyframes ing-spin { to { transform: rotate(360deg); } }

/* ── Alert ─────────────────────────────────────────────── */
.ing-alert {
  padding: .875rem 1rem; border-radius: var(--radius-sm);
  font-size: .9rem; display: flex; align-items: center;
}
.ing-alert--danger  { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
.ing-alert--success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }

/* ── Form ──────────────────────────────────────────────── */
.ing-label {
  font-size: .85rem; font-weight: 600; color: var(--navy);
  margin-bottom: .4rem; display: flex; align-items: center; gap: .35rem;
}
.ing-required { color: var(--danger); font-size: .9em; }

.ing-input {
  height: 44px; padding: .5rem .875rem;
  border: 1.5px solid var(--border); border-radius: var(--radius-sm);
  font-size: .9rem; color: var(--text); background: var(--surface);
  transition: border-color .15s, box-shadow .15s;
  width: 100%; outline: none;
}
.ing-input:focus { border-color: var(--gold); box-shadow: 0 0 0 3px rgba(226,174,118,.18); }
.ing-input.is-invalid { border-color: var(--danger); }
.ing-input--mid { border-left: none; border-right: none; border-radius: 0; }

.ing-input-group { display: flex; align-items: stretch; position: relative; }
.ing-input-addon {
  height: 44px; padding: 0 .875rem;
  background: #f8f9fb; border: 1.5px solid var(--border);
  color: var(--text-muted); font-size: .875rem;
  display: flex; align-items: center; white-space: nowrap;
}
.ing-input-addon:first-child { border-radius: var(--radius-sm) 0 0 var(--radius-sm); border-right: none; }
.ing-input-addon:last-child  { border-radius: 0 var(--radius-sm) var(--radius-sm) 0; border-left: none; }
.ing-input-group:focus-within .ing-input-addon { border-color: var(--gold); }

.ing-feedback-invalid { font-size: .8rem; color: var(--danger); margin-top: .25rem; display: none; }
.was-validated .ing-input:invalid ~ .ing-feedback-invalid,
.ing-input.is-invalid ~ .ing-feedback-invalid { display: block; }

.ing-form-actions {
  display: flex; justify-content: flex-end; gap: .75rem;
  margin-top: .5rem; padding-top: 1.25rem; border-top: 1px solid var(--border);
}

/* ── Buttons ───────────────────────────────────────────── */
.ing-btn {
  height: 44px; padding: 0 1.375rem; border-radius: var(--radius-sm);
  font-size: .9rem; font-weight: 600; cursor: pointer;
  display: inline-flex; align-items: center; justify-content: center;
  border: 1.5px solid transparent; transition: all .15s;
  text-decoration: none; white-space: nowrap;
}
.ing-btn--primary {
  background: linear-gradient(135deg, var(--navy), var(--navy-mid));
  color: var(--gold); border-color: transparent;
}
.ing-btn--primary:hover {
  background: linear-gradient(135deg, var(--navy-mid), var(--navy-light));
  color: var(--gold); transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(4,25,48,.3);
}
.ing-btn--secondary {
  background: transparent; color: var(--text-muted); border-color: var(--border);
}
.ing-btn--secondary:hover { background: #f1f3f7; color: var(--text); border-color: #c8cdd8; }
.ing-btn--confirm {
  background: linear-gradient(135deg, #14532d, #166534);
  color: #bbf7d0; border-color: transparent;
}
.ing-btn--confirm:hover {
  background: linear-gradient(135deg, #166534, #15803d);
  color: white; transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(21,128,61,.3);
}
.ing-btn--confirm:disabled { opacity: .6; cursor: not-allowed; transform: none; }

/* ── Table ─────────────────────────────────────────────── */
.ing-table {
  width: 100%; border-collapse: separate; border-spacing: 0; font-size: .875rem;
}
.ing-table thead tr th {
  background: linear-gradient(135deg, var(--navy), var(--navy-mid));
  color: var(--gold); padding: .875rem 1rem;
  font-weight: 700; font-size: .8rem;
  text-transform: uppercase; letter-spacing: .04em;
  text-align: center; border: none; white-space: nowrap; cursor: default;
}
.ing-table thead th.sortable { cursor: pointer; user-select: none; position: relative; }
.ing-table thead th.sortable:hover {
  background: linear-gradient(135deg, var(--navy-mid), var(--navy-light));
}
.sort-indicator { display: inline-block; width: 14px; font-size: .65rem; opacity: 0; transition: opacity .15s; }
th[data-sort-dir] .sort-indicator { opacity: 1; }

table.dataTable thead .sorting:after,
table.dataTable thead .sorting_asc:after,
table.dataTable thead .sorting_desc:after,
table.dataTable thead .sorting:before,
table.dataTable thead .sorting_asc:before,
table.dataTable thead .sorting_desc:before { content: '' !important; }

.ing-table tbody tr { border-bottom: 1px solid var(--border); transition: background .1s; }
.ing-table tbody tr:last-child { border-bottom: none; }
.ing-table tbody tr:hover { background: #fafbfe; }
.ing-table tbody td { padding: .875rem 1rem; vertical-align: middle; text-align: center; color: var(--text); }

.ing-price-badge {
  display: inline-flex; align-items: baseline;
  background: #f0fdf4; border: 1px solid #bbf7d0;
  color: var(--success); padding: .3rem .7rem; border-radius: 8px;
  font-weight: 700; font-size: .9rem;
}
.ing-alias-badge {
  display: inline-block;
  background: var(--gold-light); color: var(--navy);
  border: 1px solid rgba(226,174,118,.4);
  font-size: .75rem; font-weight: 600;
  padding: .2rem .6rem; border-radius: 6px;
  margin: .15rem .15rem .15rem 0;
}
.ing-table-empty { color: #cbd5e1; font-size: 1.1rem; }
.ing-date-cell {
  font-size: .8rem; color: var(--text-muted);
  display: flex; align-items: center; justify-content: center; gap: .3rem;
}
.ing-action-group { display: flex; align-items: center; justify-content: center; gap: .3rem; }
.ing-action-btn {
  width: 32px; height: 32px; border-radius: 7px;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: .8rem; cursor: pointer; transition: all .15s;
  border: 1.5px solid transparent; background: transparent; text-decoration: none;
}
.ing-action-btn--edit  { border-color: rgba(226,174,118,.5); color: var(--gold-dark); }
.ing-action-btn--edit:hover { background: var(--gold-light); color: var(--gold-dark); }
.ing-action-btn--view  { border-color: rgba(4,25,48,.2); color: var(--navy); }
.ing-action-btn--view:hover { background: #e8edf5; color: var(--navy); }
.ing-action-btn--delete { border-color: rgba(220,38,38,.3); color: var(--danger); }
.ing-action-btn--delete:hover { background: #fef2f2; color: #b91c1c; }

/* Bulk preview table header */
#bulkCostPreviewTable thead th {
  background: linear-gradient(135deg, var(--navy), var(--navy-mid)) !important;
  color: var(--gold) !important;
  text-align: center; vertical-align: middle;
}

/* DataTables overrides */
.dataTables_wrapper .dataTables_filter input {
  height: 38px; padding: .4rem .875rem;
  border: 1.5px solid var(--border); border-radius: var(--radius-sm);
  font-size: .875rem; outline: none; transition: border-color .15s; margin-left: .5rem;
}
.dataTables_wrapper .dataTables_filter input:focus { border-color: var(--gold); }
.dataTables_wrapper .dataTables_length select {
  height: 38px; padding: .4rem 2rem .4rem .75rem;
  border: 1.5px solid var(--border); border-radius: var(--radius-sm);
  font-size: .875rem; margin: 0 .5rem;
}
.dataTables_wrapper .dataTables_info { font-size: .82rem; color: var(--text-muted); }
.dataTables_wrapper .dataTables_paginate { display: flex; gap: .3rem; align-items: center; }
.dataTables_wrapper .dataTables_paginate .paginate_button {
  min-width: 34px; height: 34px; padding: 0 .5rem;
  display: inline-flex; align-items: center; justify-content: center;
  border: 1px solid var(--border) !important; border-radius: 7px;
  font-size: .82rem; cursor: pointer; transition: all .15s;
  color: var(--text) !important; background: var(--surface) !important;
}
.dataTables_wrapper .dataTables_paginate .paginate_button.current,
.dataTables_wrapper .dataTables_paginate .paginate_button:hover {
  background: var(--navy) !important; color: var(--gold) !important; border-color: var(--navy) !important;
}
.dataTables_wrapper > .row { margin: 0; padding: 1rem 1.5rem; }
.dataTables_wrapper > .row:first-child { border-bottom: 1px solid var(--border); }
.dataTables_wrapper > .row:last-child  { border-top: 1px solid var(--border); }

.duplicate-badge { font-size: .85rem; font-weight: 600; }
</style>

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {

    /* ── DataTable ─────────────────────────────────────── */
    if (window.$ && $.fn.DataTable) {
        $.fn.dataTable.ext.errMode = 'none';
        const STORAGE_KEY = 'costs_sort_state';

        var table = $('#costTable').DataTable({
            paging: true, ordering: true, orderMulti: false, responsive: true,
            pageLength: $('#costTable').data('page-length') || 10,
            order: [[3, 'desc']],
            columnDefs: [{ orderable: false, targets: 5 }],
            language: {
                search: "Cerca:", lengthMenu: "Mostra _MENU_ voci per pagina",
                info: "Visualizzati da _START_ a _END_ di _TOTAL_ costi",
                paginate: { previous: "«", next: "»" },
                zeroRecords: "Nessun costo trovato"
            }
        });

        $.fn.dataTable.ext.search.push(function(settings, data) {
            if (settings.nTable.id !== 'costTable') return true;
            var selected = ($('#filterMonth').val() || '').trim();
            if (!selected) return true;
            var dueDate = (data[3] || '').trim();
            // extract YYYY-MM from the rendered date (d/m/Y format)
            var parts = dueDate.match(/(\d{2})\/(\d{2})\/(\d{4})/);
            if (parts) { var iso = parts[3]+'-'+parts[2]; return iso === selected; }
            return dueDate.substring(0, 7) === selected;
        });

        try {
            const saved = sessionStorage.getItem(STORAGE_KEY);
            if (saved) {
                const { col, dir } = JSON.parse(saved);
                if (typeof col === 'number' && (dir === 'asc' || dir === 'desc')) table.order([col, dir]).draw(false);
            }
        } catch(e){}

        function updateIndicators() {
            $('#costTable thead th.sortable').removeAttr('data-sort-dir').find('.sort-indicator').text('');
            const ord = table.order();
            if (!ord.length) return;
            const th = $('#costTable thead th').eq(ord[0][0]);
            if (!th.hasClass('sortable')) return;
            th.attr('data-sort-dir', ord[0][1]);
            th.find('.sort-indicator').text(ord[0][1] === 'asc' ? '▲' : '▼');
        }
        updateIndicators();

        $('#costTable thead').on('click', 'th.sortable', function() {
            const idx = $(this).index();
            if (table.settings()[0].aoColumns[idx].bSortable === false) return;
            const current = table.order();
            const currentCol = current.length ? current[0][0] : null;
            const currentDir = current.length ? current[0][1] : 'asc';
            const newDir = (currentCol === idx && currentDir === 'asc') ? 'desc' : 'asc';
            table.order([idx, newDir]).draw();
            updateIndicators();
            try {
                const ord = table.order();
                sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ col: ord[0][0], dir: ord[0][1] }));
            } catch(e){}
        });

        $('#costTable thead').on('mousedown', 'th', function(e) { if (e.shiftKey) e.preventDefault(); });
        table.draw();
        $('#filterMonth').on('change', function() { table.draw(); });
    }

    /* ── Form validation ───────────────────────────────── */
    document.querySelectorAll('.needs-validation').forEach(form => {
        form.addEventListener('submit', e => {
            if (!form.checkValidity()) { e.preventDefault(); e.stopPropagation(); }
            form.classList.add('was-validated');
        }, false);
    });

    /* ── Dropzone ──────────────────────────────────────── */
    const previewTableBody = document.querySelector('#bulkCostPreviewTable tbody');
    const bulkPreviewWrapper = document.getElementById('bulkPreviewWrapper');
    const bulkSaveResult = document.getElementById('bulkSaveResult');
    const bulkSummaryText = document.getElementById('bulkSummaryText');
    const saveBulkCostsBtn = document.getElementById('saveBulkCostsBtn');
    const bulkSupplier = document.getElementById('bulk_supplier_name');
    const bulkInvoiceCode = document.getElementById('bulk_invoice_code');
    const bulkDate = document.getElementById('bulk_date');

    function escapeHtml(str) {
        return String(str ?? '')
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }

    function showCostErr(msg) {
        const el = document.getElementById('extractStatus');
        document.getElementById('extractStatusMsg').textContent = msg;
        el.classList.remove('d-none');
    }
    function hideCostErr() { document.getElementById('extractStatus').classList.add('d-none'); }

    function showDzLoading(step) {
        document.getElementById('costDzContent').classList.add('d-none');
        document.getElementById('costDzLoading').classList.remove('d-none');
        document.getElementById('costDzStep').textContent = step;
    }
    function hideDzLoading() {
        document.getElementById('costDzContent').classList.remove('d-none');
        document.getElementById('costDzLoading').classList.add('d-none');
    }

    window.handleCostDrop = function(e) {
        e.preventDefault();
        document.getElementById('costDropZone').classList.remove('ing-dropzone--active');
        const file = e.dataTransfer.files[0];
        if (file) processCostFile(file);
    };

    window.handleCostFileSelect = function(input) {
        const file = input.files[0];
        if (file) processCostFile(file);
        input.value = '';
    };

    async function processCostFile(file) {
        const allowed = ['image/jpeg','image/png','image/webp','application/pdf'];
        if (!allowed.includes(file.type)) { showCostErr('Tipo file non supportato. Usa JPG, PNG, WEBP o PDF.'); return; }
        if (file.size > 20*1024*1024)     { showCostErr('File troppo grande. Massimo 20 MB.'); return; }

        hideCostErr();
        showDzLoading('Analisi OCR con Google Vision...');
        bulkPreviewWrapper.classList.add('d-none');
        bulkSaveResult.innerHTML = '';

        const fd = new FormData();
        fd.append('invoice', file);
        fd.append('_token', document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');

        try {
            const resp = await fetch('{{ route("costs.extractInvoice") }}', { method: 'POST', body: fd });
            document.getElementById('costDzStep').textContent = 'Parsing intelligente con AI...';
            const data = await resp.json();
            hideDzLoading();

            if (!resp.ok || data.error) { showCostErr('⚠ ' + (data.error || 'Errore sconosciuto.')); return; }
            if (!Array.isArray(data.items) || data.items.length === 0) {
                showCostErr('Nessuna riga costo trovata nel documento.');
                return;
            }

            bulkSupplier.value    = data.supplier_name || '';
            bulkInvoiceCode.value = data.invoice_code  || '';
            bulkDate.value        = data.date           || '';

            renderRows(data.items);
            bulkPreviewWrapper.classList.remove('d-none');
        } catch (err) {
            hideDzLoading();
            showCostErr('Errore di rete: ' + err.message);
        }
    }

    function renderRows(items) {
        previewTableBody.innerHTML = '';
        items.forEach((item, index) => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>
                    <input type="text" class="ing-input bulk-description" value="${escapeHtml(item.description)}" data-index="${index}">
                </td>
                <td>
                    <div class="ing-input-group">
                        <span class="ing-input-addon">€</span>
                        <input type="number" step="0.01" class="ing-input ing-input--mid bulk-amount"
                               value="${Number(item.amount ?? 0).toFixed(2)}" data-index="${index}">
                    </div>
                </td>
                <td>
                    <input type="hidden" class="bulk-category-id" value="${item.suggested_category_id || ''}">
                    <div class="fw-semibold">${escapeHtml(item.category_name || 'Other')}</div>
                    <small class="text-muted d-block mt-1">categoria da fattura</small>
                </td>
                <td>
                    <input type="text" class="ing-input bulk-other-category"
                           value="${escapeHtml(item.description)}" data-index="${index}">
                </td>
                <td class="text-center bulk-row-status">
                    ${item.is_duplicate
                        ? '<span class="badge bg-warning text-dark duplicate-badge">Duplicato</span>'
                        : '<span class="badge bg-success duplicate-badge">Nuovo</span>'
                    }
                </td>
            `;
            previewTableBody.appendChild(row);
        });
        updateSummary();
    }

    function collectRows() {
        const rows = [];
        previewTableBody.querySelectorAll('tr').forEach(tr => {
            rows.push({
                description:    tr.querySelector('.bulk-description')?.value?.trim() || '',
                amount:         tr.querySelector('.bulk-amount')?.value || 0,
                category_id:    tr.querySelector('.bulk-category-id')?.value || '',
                other_category: tr.querySelector('.bulk-other-category')?.value?.trim() || '',
                skip: false,
            });
        });
        return rows;
    }

    function updateSummary() {
        const rows  = collectRows();
        const total = rows.reduce((sum, r) => sum + (parseFloat(r.amount || 0) || 0), 0);
        bulkSummaryText.textContent = `${rows.length} righe pronte per il salvataggio, totale €${total.toFixed(2)}`;
    }

    previewTableBody?.addEventListener('input',  updateSummary);
    previewTableBody?.addEventListener('change', updateSummary);

    if (saveBulkCostsBtn) {
        saveBulkCostsBtn.addEventListener('click', async () => {
            const items = collectRows();

            if (!bulkSupplier.value.trim()) {
                bulkSaveResult.innerHTML = `<div class="ing-alert ing-alert--danger mt-2">Inserisci il fornitore.</div>`;
                return;
            }
            if (!bulkDate.value) {
                bulkSaveResult.innerHTML = `<div class="ing-alert ing-alert--danger mt-2">Inserisci la data.</div>`;
                return;
            }
            if (items.length === 0) {
                bulkSaveResult.innerHTML = `<div class="ing-alert ing-alert--danger mt-2">Non ci sono righe da salvare.</div>`;
                return;
            }

            saveBulkCostsBtn.disabled = true;
            bulkSaveResult.innerHTML = `<div class="ing-alert" style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;">
                <span class="spinner-border spinner-border-sm me-2"></span>Salvataggio in corso...
            </div>`;

            try {
                const response = await fetch(`{{ route('costs.processInvoice') }}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        supplier_name: bulkSupplier.value.trim(),
                        invoice_code:  bulkInvoiceCode.value.trim(),
                        date:          bulkDate.value,
                        items:         items,
                    }),
                });

                const data = await response.json();

                if (!response.ok) throw new Error(data.message || data.error || 'Errore durante il salvataggio.');

                bulkSaveResult.innerHTML = `<div class="ing-alert ing-alert--success">${escapeHtml(data.message || 'Costi salvati con successo.')}</div>`;
                setTimeout(() => { window.location.reload(); }, 1200);
            } catch (error) {
                bulkSaveResult.innerHTML = `<div class="ing-alert ing-alert--danger">${escapeHtml(error.message || 'Errore sconosciuto')}</div>`;
            } finally {
                saveBulkCostsBtn.disabled = false;
            }
        });
    }
});
</script>
@endsection