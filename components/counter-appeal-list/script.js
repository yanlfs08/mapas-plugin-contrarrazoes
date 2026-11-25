app.component('counter-appeal-list', {
    template: $TEMPLATES['counter-appeal-list'],

    props: {
        // mostra só a fase de contrarrazões informada (usado no modal da linha do tempo)
        counterId: {
            type: [Number, String],
            default: null,
        },
        // esconde título e prazo, já exibidos no bloco da linha do tempo
        hideHeader: {
            type: Boolean,
            default: false,
        },
    },

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
            registrationNumber: config.registrationNumber || null,
            allPhases: config.phases || [],
            filters: {},
            selectedAuthor: {},
            processing: null,
            // contrarrazões abertas com "Exibir contrarrazão" (id => true)
            expanded: {},
        };
    },

    mounted() {
        // &contrarrazao={id}: chegou da ficha da contrarrazão (ou logo depois do envio) -> já abre ela
        const id = new URLSearchParams(window.location.search).get('contrarrazao');
        if (id) {
            this.expanded[id] = true;
        }
    },

    computed: {
        phases() {
            if (!this.counterId) {
                return this.allPhases;
            }
            return this.allPhases.filter((phase) => String(phase.counterId) === String(this.counterId));
        },
    },

    methods: {
        filteredAppeals(phase) {
            const term = (this.filters[phase.counterId] || '').trim().toLowerCase();
            const appeals = !term ? phase.appeals : phase.appeals.filter((appeal) => {
                return String(appeal.number).toLowerCase().includes(term)
                    || String(appeal.agentName || '').toLowerCase().includes(term);
            });
            // o recurso do próprio inscrito vem primeiro (destacado em laranja); os demais mantêm a ordem
            return [...appeals.filter((a) => a.isMine), ...appeals.filter((a) => !a.isMine)];
        },

        // grupos do modal: "Seu recurso" e "Recursos dos demais candidatos". Os títulos e a divisória
        // só aparecem quando o inscrito tem recurso; sem recurso próprio, a lista segue sem títulos.
        appealGroups(phase) {
            const appeals = this.filteredAppeals(phase);
            const mine = appeals.filter((a) => a.isMine);
            const others = appeals.filter((a) => !a.isMine);
            const hasMine = phase.appeals.some((a) => a.isMine);
            const groups = [];
            if (mine.length) {
                groups.push({ key: 'mine', items: mine, titled: true });
            }
            if (others.length) {
                groups.push({ key: 'others', items: others, titled: hasMine });
            }
            return groups;
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
                if (data.status > 0) {
                    // já enviada: mostra aqui mesmo, sem sair da linha do tempo
                    this.expanded[data.id] = true;
                    this.processing = null;
                    return;
                }
                this.messages.success(this.text('Contrarrazão criada'));
                window.location.href = data.editUrl;
            } catch (error) {
                this.messages.error(error?.data || error);
                this.processing = null;
            }
        },

        toggle(id) {
            this.expanded[id] = !this.expanded[id];
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
