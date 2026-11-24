app.component('counter-appeal-config', {
    template: $TEMPLATES['counter-appeal-config'],

    props: {
        // fase de recurso cuja etapa recebe o acordeão "Contrarrazões"
        appealPhaseId: {
            type: [Number, String],
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
        const found = config.find((item) => String(item.appealPhase.id) === String(this.appealPhaseId));

        // fase de recurso criada depois do carregamento da página: ainda sem dados do servidor
        const item = this.buildItem(found || {
            appealPhase: { id: this.appealPhaseId },
            publicFieldOptions: [],
            counter: null,
            counterAppeals: [],
        });
        return { item };
    },

    methods: {
        buildItem(item) {
            const counter = item.counter;
            return {
                ...item,
                processing: false,
                saving: false,
                counterEntity: counter ? this.toEntity(counter) : null,
                form: this.buildForm(counter),
            };
        },

        buildForm(counter) {
            const results = counter?.counterAppealResponseResults || [];
            return {
                counterAppealPublicFields: [...(counter?.counterAppealPublicFields || [])],
                counterAppealResponseClassify: results.length > 0 || !counter,
                counterAppealResponseResults: results.length ? [...results] : [],
            };
        },

        // ao ligar a classificação sem opções, começa pelas opções padrão do plugin
        onClassifyChange(item) {
            if (item.form.counterAppealResponseClassify && !item.form.counterAppealResponseResults.length) {
                item.form.counterAppealResponseResults = [...($MAPAS.config.counterAppealDefaultResponseResults || [])];
            }
        },

        addResultOption(item) {
            item.form.counterAppealResponseResults.push('');
        },

        removeResultOption(item, index) {
            item.form.counterAppealResponseResults.splice(index, 1);
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
                item.form = this.buildForm(data);
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
                counterAppealPublicFields: item.form.counterAppealPublicFields.length ? item.form.counterAppealPublicFields : '',
                registrationFrom: counter.registrationFrom ? counter.registrationFrom.sql() : '',
                registrationTo: counter.registrationTo ? counter.registrationTo.sql() : '',
                counterAppealResponseClassify: item.form.counterAppealResponseClassify,
                counterAppealResponseResults: item.form.counterAppealResponseResults
                    .map((option) => String(option || '').trim())
                    .filter((option) => option),
            };

            if (payload.counterAppealResponseClassify && !payload.counterAppealResponseResults.length) {
                this.messages.error(this.text('Informe ao menos uma opção'));
                item.saving = false;
                return;
            }

            try {
                const data = await counter.invoke('saveCounterAppealConfig', payload);
                item.counterEntity = this.toEntity(data);
                item.form = this.buildForm(data);
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
                item.form = this.buildForm(null);
                this.messages.success(this.text('Fase de contrarrazões excluída'));
            } catch (error) {
                this.messages.error(error?.data || error);
            }
        },

        sentCount(item) {
            return item.counterAppeals.filter((ca) => ca.status > 0).length;
        },

        responseLabel(ca) {
            if (ca.status <= 0) {
                return '—';
            }
            const responses = ca.responses || [];
            if (!responses.length) {
                return this.text('Pendente');
            }
            const results = responses.map((r) => r.result).filter((r) => r);
            return results.length ? results.join(' / ') : this.text('Respondida');
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
