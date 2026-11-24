app.component('counter-appeal-evaluation', {
    template: $TEMPLATES['counter-appeal-evaluation'],

    setup() {
        const text = Utils.getTexts('counter-appeal-evaluation');
        const messages = useMessages();
        return { text, messages };
    },

    data() {
        const config = $MAPAS.config.counterAppealEvaluation || {};
        const items = (config.items || []).map((item) => ({
            ...item,
            editing: !item.response,
            saving: false,
            errors: {},
            form: {
                result: item.response?.result || '',
                text: item.response?.text || '',
            },
        }));

        return { config, items };
    },

    computed: {
        pendingCount() {
            return this.items.filter((item) => !item.response?.text).length;
        },

        answeredCount() {
            return this.items.length - this.pendingCount;
        },

        hasResultOptions() {
            return (this.config.resultOptions || []).length > 0;
        },
    },

    methods: {
        edit(item) {
            item.form.result = item.response?.result || '';
            item.form.text = item.response?.text || '';
            item.errors = {};
            item.editing = true;
        },

        cancel(item) {
            item.errors = {};
            item.editing = false;
        },

        async save(item) {
            item.errors = {};
            if (this.hasResultOptions && !item.form.result) {
                item.errors.result = this.text('Selecione a classificação');
            }
            if (!item.form.text.trim()) {
                item.errors.text = this.text('Escreva a fundamentação');
            }
            if (Object.keys(item.errors).length) {
                return;
            }

            item.saving = true;
            try {
                const entity = new Entity('registration', item.id);
                const data = await entity.invoke('saveCounterAppealResponse', {
                    result: item.form.result,
                    text: item.form.text,
                });
                item.response = data.response;
                item.editing = false;
                this.messages.success(this.text('Resposta salva'));
            } catch (error) {
                const data = error?.data;
                if (data && typeof data === 'object') {
                    item.errors = Object.fromEntries(Object.entries(data).map(([key, value]) => [key, [].concat(value).join(' ')]));
                    this.messages.error(Object.values(item.errors).join(' '));
                } else {
                    this.messages.error(data || error);
                }
            }
            item.saving = false;
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
