/**
 * Bloco "[Contrarrazões]" na linha do tempo da inscrição (opportunity-phases-timeline),
 * abaixo do "Solicitar recurso" da fase avaliada. O botão abre a lista de recursos num modal.
 * Os dados vêm de $MAPAS.config.counterAppealList (montados para a inscrição da página).
 */
app.component('counter-appeal-timeline', {
    template: $TEMPLATES['counter-appeal-timeline'],

    props: {
        // item da linha do tempo: oportunidade (fase de coleta) ou configuração de avaliação
        phase: {
            type: Object,
            required: true,
        },
    },

    computed: {
        // oportunidade da fase do item (o recurso é vinculado à oportunidade da fase avaliada)
        phaseOpportunityId() {
            const item = this.phase;
            if (item.__objectType === 'evaluationmethodconfiguration') {
                return item.opportunity?.id ?? item.opportunity;
            }
            // fase de coleta com avaliação própria: o bloco fica no item da avaliação
            if (item.evaluationMethodConfiguration) {
                return null;
            }
            return item.id;
        },

        data() {
            const phases = $MAPAS.config.counterAppealList?.phases || [];
            const id = this.phaseOpportunityId;
            return id ? phases.find((p) => String(p.evaluatedPhaseId) === String(id)) || null : null;
        },

        sentCount() {
            return this.myCounterAppeals.filter((ca) => ca.status > 0).length;
        },

        draftCount() {
            return this.myCounterAppeals.filter((ca) => ca.status == 0).length;
        },

        myCounterAppeals() {
            return (this.data?.appeals || []).flatMap((appeal) => appeal.myCounterAppeals || []);
        },

        // respostas publicadas: às contrarrazões desta inscrição e às contrarrazões contra o recurso dela
        responsesCount() {
            if (!this.data?.resultPublished) {
                return 0;
            }
            return (this.data.appeals || []).reduce((total, appeal) => {
                const mine = (appeal.myCounterAppeals || []).filter((ca) => (ca.responses || []).length).length;
                const received = (appeal.receivedCounterAppeals || []).filter((ca) => (ca.responses || []).length).length;
                return total + mine + received;
            }, 0);
        },

        // recursos de outras pessoas que esta inscrição ainda não contestou
        availableCount() {
            return (this.data?.appeals || []).filter((appeal) => !appeal.isMine && !(appeal.myCounterAppeals || []).length).length;
        },
    },

    mounted() {
        // ?contrarrazoes={id da fase} abre a lista direto (link "Ver os recursos" da contrarrazão)
        const requested = new URLSearchParams(window.location.search).get('contrarrazoes');
        if (this.data && requested && String(requested) === String(this.data.counterId)) {
            this.$nextTick(() => this.$refs.modal?.open());
        }
    },

    methods: {
        formatDate(value) {
            if (!value) {
                return '—';
            }
            const date = new McDate(value);
            return `${date.date('2-digit year')} ${date.time('2-digit')}`;
        },
    },
});
