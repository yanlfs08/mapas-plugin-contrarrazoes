/**
 * Acordeão "Contrarrazões" na aba "Inscrições e Resultados" da edição da oportunidade (gestor).
 * Só o resumo (prazo, trava e contagens) e o botão "Lista de contrarrazões", que abre em nova aba
 * a página de acompanhamento (counter-appeal-tracking). A configuração fica na aba
 * "Configuração de fases". Os dados vêm de $MAPAS.config.counterAppealConfig.
 */
app.component('counter-appeal-registrations', {
    template: $TEMPLATES['counter-appeal-registrations'],

    props: {
        // fase de recurso cuja etapa recebe o acordeão
        appealPhaseId: {
            type: [Number, String],
            required: true,
        },
    },

    data() {
        const config = $MAPAS.config.counterAppealConfig || [];
        const item = config.find((i) => String(i.appealPhase.id) === String(this.appealPhaseId)) || null;
        const counter = item?.counter ? Entity.fromJson({ '@entityType': 'opportunity', ...item.counter }) : null;
        return { item, counter };
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

        // situação do prazo de contrarrazões: notStarted, open, closed ou undefined (sem prazo)
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

        // o julgamento e a publicação ficam travados até o fim do prazo (ou sem prazo)
        locked() {
            return this.period !== 'closed';
        },

        contestedCount() {
            const contested = new Set(this.sent.map((ca) => String(ca.targetId)));
            return this.appeals.filter((appeal) => contested.has(String(appeal.id))).length;
        },

        authorsCount() {
            return new Set(this.sent.map((ca) => String(ca.authorNumber))).size;
        },
    },

    methods: {
        formatDate(value) {
            if (!value) {
                return '';
            }
            const date = value instanceof McDate ? value : new McDate(value);
            return `${date.date('2-digit year')} ${date.time('2-digit')}`;
        },
    },
});
