app.component('counter-appeal-evaluation', {
    template: $TEMPLATES['counter-appeal-evaluation'],

    data() {
        const config = $MAPAS.config.counterAppealEvaluation || {};
        return {
            config,
            items: config.items || [],
        };
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
