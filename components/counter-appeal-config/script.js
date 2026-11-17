app.component('counter-appeal-config', {
    template: $TEMPLATES['counter-appeal-config'],

    props: {
        entity: {
            type: Entity,
            required: true,
        },
    },

    setup() {
        const text = Utils.getTexts('counter-appeal-config');
        const messages = useMessages();
        return { text, messages };
    },

    data() {
        const config = $MAPAS.config.counterAppealConfig || [];
        const items = config.map((item) => this.buildItem(item));
        return { items };
    },

    methods: {
        buildItem(item) {
            const counter = item.counter;
            return {
                ...item,
                processing: false,
                saving: false,
                counterEntity: counter ? this.toEntity(counter) : null,
                form: {
                    counterAppealEligibility: counter?.counterAppealEligibility || 'all',
                    counterAppealPublicFields: [...(counter?.counterAppealPublicFields || [])],
                },
            };
        },

        toEntity(data) {
            const entity = Entity.fromJson({ '@entityType': 'opportunity', ...data });
            entity.formBuilderUrl = data.formBuilderUrl;
            return entity;
        },

        appealPhaseEntity(item) {
            return new Entity('opportunity', item.appealPhase.id);
        },

        async createCounterAppealPhase(item) {
            item.processing = true;
            try {
                const data = await this.appealPhaseEntity(item).invoke('createCounterAppealPhase', {});
                item.counterEntity = this.toEntity(data);
                item.form.counterAppealEligibility = data.counterAppealEligibility || 'all';
                item.form.counterAppealPublicFields = [...(data.counterAppealPublicFields || [])];
                this.messages.success(this.text('Fase de contrarrazões criada com sucesso'));
            } catch (error) {
                this.messages.error(error?.data || error);
            }
            item.processing = false;
        },

        async saveConfig(item) {
            item.saving = true;
            const counter = item.counterEntity;
            const payload = {
                counterAppealEligibility: item.form.counterAppealEligibility,
                counterAppealPublicFields: item.form.counterAppealPublicFields.length ? item.form.counterAppealPublicFields : '',
                registrationFrom: counter.registrationFrom ? counter.registrationFrom.sql() : '',
                registrationTo: counter.registrationTo ? counter.registrationTo.sql() : '',
            };

            try {
                const data = await counter.invoke('saveCounterAppealConfig', payload);
                item.counterEntity = this.toEntity(data);
                item.form.counterAppealPublicFields = [...(data.counterAppealPublicFields || [])];
                this.messages.success(this.text('Configurações salvas'));
            } catch (error) {
                const data = error?.data;
                if (data && typeof data === 'object') {
                    this.messages.error(Object.values(data).flat().join(' '));
                } else {
                    this.messages.error(data || error);
                }
            }
            item.saving = false;
        },

        async deleteCounterAppealPhase(item) {
            try {
                await item.counterEntity.invoke('deleteCounterAppealPhase', {});
                item.counterEntity = null;
                item.counterAppeals = [];
                item.form.counterAppealPublicFields = [];
                this.messages.success(this.text('Fase de contrarrazões excluída'));
            } catch (error) {
                this.messages.error(error?.data || error);
            }
        },

        sentCount(item) {
            return item.counterAppeals.filter((ca) => ca.status > 0).length;
        },

        statusLabel(status) {
            return status > 0 ? this.text('Enviada') : this.text('Rascunho');
        },

        formatDate(value) {
            if (!value) {
                return '';
            }
            const date = new McDate(value);
            return `${date.date('2-digit year')} ${date.time('2-digit')}`;
        },

        fieldTypeLabel(type) {
            const labels = {
                'text': 'texto',
                'textarea': 'texto longo',
                'select': 'seleção',
                'checkboxes': 'múltipla escolha',
                'checkbox': 'caixa de seleção',
                'number': 'número',
                'currency': 'moeda',
                'date': 'data',
                'url': 'link',
                'links': 'links',
                'custom-table': 'tabela',
                'municipio': 'município',
                'file': 'anexo',
            };
            return labels[type] ? this.text(labels[type]) : type;
        },
    },
});
