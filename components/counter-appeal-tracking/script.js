/**
 * Listas de acompanhamento das contrarrazões de uma fase de recurso (gestor), na página
 * GET /opportunity/counterAppeals/{id da fase de recurso}, aberta em nova aba pelo botão
 * "Lista de contrarrazões" da aba "Inscrições e Resultados".
 * Dados: $MAPAS.config.counterAppealTracking (Plugin::buildPhaseConfigItem + editUrl).
 */
app.component('counter-appeal-tracking', {
    template: $TEMPLATES['counter-appeal-tracking'],

    setup() {
        const text = Utils.getTexts('counter-appeal-tracking');
        return { text };
    },

    data() {
        const item = $MAPAS.config.counterAppealTracking || null;
        const counter = item?.counter ? Entity.fromJson({ '@entityType': 'opportunity', ...item.counter }) : null;
        // ?recurso={id} já abre a lista filtrada por um recurso
        const appeal = new URLSearchParams(window.location.search).get('recurso') || '';

        return {
            item,
            counter,
            filters: {
                appeal,
                status: '',
                search: '',
            },
        };
    },

    computed: {
        counterAppeals() {
            return this.item?.counterAppeals || [];
        },

        appeals() {
            return this.item?.appeals || [];
        },

        sent() {
            return this.counterAppeals.filter((ca) => ca.status > 0);
        },

        drafts() {
            return this.counterAppeals.filter((ca) => ca.status == 0);
        },

        period() {
            const from = this.counter?.registrationFrom;
            const to = this.counter?.registrationTo;
            if (!from || !to) {
                return 'undefined';
            }
            if (from.isFuture()) {
                return 'notStarted';
            }
            return to.isPast() ? 'closed' : 'open';
        },

        // um resumo por recurso enviado
        appealRows() {
            return this.appeals.map((appeal) => {
                const mine = this.counterAppeals.filter((ca) => String(ca.targetId) === String(appeal.id));
                return {
                    ...appeal,
                    sentCount: mine.filter((ca) => ca.status > 0).length,
                    draftCount: mine.filter((ca) => ca.status == 0).length,
                };
            });
        },

        filtered() {
            const term = this.filters.search.trim().toLowerCase();
            return this.counterAppeals.filter((ca) => {
                if (this.filters.appeal && String(ca.targetId) !== String(this.filters.appeal)) {
                    return false;
                }
                if (this.filters.status === 'sent' && !(ca.status > 0)) {
                    return false;
                }
                if (this.filters.status === 'draft' && ca.status != 0) {
                    return false;
                }
                if (term) {
                    const haystack = [ca.number, ca.agentName, ca.authorNumber, ca.targetNumber, ca.targetAgentName]
                        .map((v) => String(v || '').toLowerCase()).join(' ');
                    return haystack.includes(term);
                }
                return true;
            });
        },

        hasFilters() {
            return !!(this.filters.appeal || this.filters.status || this.filters.search.trim());
        },
    },

    methods: {
        filterByAppeal(row) {
            this.filters.appeal = row.id;
            this.filters.status = '';
            this.filters.search = '';
            this.$nextTick(() => this.$refs.list?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
        },

        clearFilters() {
            this.filters.appeal = '';
            this.filters.status = '';
            this.filters.search = '';
        },

        statusLabel(status) {
            return status > 0 ? this.text('Enviada') : this.text('Rascunho');
        },

        formatDate(value) {
            if (!value) {
                return '';
            }
            const date = value instanceof McDate ? value : new McDate(value);
            return `${date.date('2-digit year')} ${date.time('2-digit')}`;
        },

        // planilha (CSV separado por ponto e vírgula, com BOM, para abrir direto no Excel/LibreOffice)
        exportCsv() {
            const header = [
                this.text('Contrarrazão'), this.text('Situação'), this.text('Enviada em'),
                this.text('Inscrição do autor'), this.text('Autor'),
                this.text('Recurso contestado'), this.text('Proponente do recurso'),
            ];
            const rows = this.filtered.map((ca) => [
                ca.number, this.statusLabel(ca.status), this.formatDate(ca.sentTimestamp),
                ca.authorNumber, ca.agentName, ca.targetNumber, ca.targetAgentName,
            ]);
            const escape = (value) => `"${String(value ?? '').replace(/"/g, '""')}"`;
            const csv = [header, ...rows].map((row) => row.map(escape).join(';')).join('\r\n');
            const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `contrarrazoes-fase-${this.counter?.id || this.item?.appealPhase?.id}.csv`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
        },
    },
});
