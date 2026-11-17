app.component('counter-appeal-list', {
    template: $TEMPLATES['counter-appeal-list'],

    setup() {
        const text = Utils.getTexts('counter-appeal-list');
        const messages = useMessages();
        return { text, messages };
    },

    data() {
        const config = $MAPAS.config.counterAppealList || {};
        return {
            isGuest: !!config.isGuest,
            loginUrl: config.loginUrl,
            phases: config.phases || [],
            filters: {},
            selectedAuthor: {},
            processing: null,
        };
    },

    methods: {
        filteredAppeals(phase) {
            const term = (this.filters[phase.counterId] || '').trim().toLowerCase();
            if (!term) {
                return phase.appeals;
            }
            return phase.appeals.filter((appeal) => {
                return String(appeal.number).toLowerCase().includes(term)
                    || String(appeal.agentName || '').toLowerCase().includes(term);
            });
        },

        // inscrições do usuário que ainda podem apresentar contrarrazão a este recurso
        availableAuthors(phase, appeal) {
            const used = appeal.myCounterAppeals.map((ca) => String(ca.authorNumber));
            return phase.authorRegistrations.filter((author) => {
                return String(author.number) !== String(appeal.number) && !used.includes(String(author.number));
            });
        },

        async createCounterAppeal(phase, appeal) {
            const authors = this.availableAuthors(phase, appeal);
            const authorId = authors.length === 1 ? authors[0].id : this.selectedAuthor[appeal.id];

            if (!authorId) {
                this.messages.error(this.text('Selecione a inscrição'));
                return;
            }

            this.processing = appeal.id;
            try {
                const counter = new Entity('opportunity', phase.counterId);
                const data = await counter.invoke('createCounterAppealRegistration', {
                    appeal_registration_id: appeal.id,
                    author_registration_id: authorId,
                });
                this.messages.success(this.text('Contrarrazão criada'));
                window.location.href = data.status > 0 ? data.singleUrl : data.editUrl;
            } catch (error) {
                this.messages.error(error?.data || error);
                this.processing = null;
            }
        },

        formatDate(value) {
            if (!value) {
                return '—';
            }
            const date = new McDate(value);
            return `${date.date('2-digit year')} ${date.time('2-digit')}`;
        },
    },
});
