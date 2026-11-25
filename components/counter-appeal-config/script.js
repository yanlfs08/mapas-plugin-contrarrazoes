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

    computed: {
        payloadKey() {
            return JSON.stringify(this.payload(this.item));
        },
    },

    watch: {
        payloadKey(value, old) {
            // a primeira leitura (e a troca de fase criada/excluída) não grava nada
            if (!old || old === 'null' || value === 'null' || value === this.lastSaved) {
                return;
            }
            this.scheduleSave(this.item);
        },
    },

    created() {
        this.lastSaved = JSON.stringify(this.payload(this.item));
    },

    beforeUnmount() {
        clearTimeout(this.saveTimer);
    },

    methods: {
        buildItem(item) {
            const counter = item.counter;
            return {
                ...item,
                processing: false,
                saving: false,
                saved: false,
                formFieldsCount: counter?.formFieldsCount ?? 0,
                counterEntity: counter ? this.toEntity(counter) : null,
                form: this.formFrom(counter),
            };
        },

        formFrom(counter) {
            return {
                counterAppealPublicFields: [...(counter?.counterAppealPublicFields || [])],
                counterAppealShowNames: counter ? counter.counterAppealShowNames !== false : true,
                counterAppealVisibleToAppellant: !!counter?.counterAppealVisibleToAppellant,
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
                item.form = this.formFrom(data);
                item.formFieldsCount = data.formFieldsCount;
                this.messages.success(this.text('Fase de contrarrazões criada com sucesso'));
            } catch (error) {
                this.messages.error(error?.data || error);
            }
            item.processing = false;
        },

        // Salvamento automático, como os demais campos da etapa de recurso (entity-field :autosave):
        // não há botão "Salvar" próprio, que se confundia com o botão "Salvar" do Mapas.
        scheduleSave(item) {
            if (!item.counterEntity) {
                return;
            }
            clearTimeout(this.saveTimer);
            item.saved = false;
            this.saveTimer = setTimeout(() => this.saveConfig(item), 1500);
        },

        payload(item) {
            const counter = item.counterEntity;
            if (!counter) {
                return null;
            }
            return {
                counterAppealPublicFields: item.form.counterAppealPublicFields.length ? item.form.counterAppealPublicFields : '',
                counterAppealShowNames: item.form.counterAppealShowNames ? 1 : 0,
                counterAppealVisibleToAppellant: item.form.counterAppealVisibleToAppellant ? 1 : 0,
                registrationFrom: counter.registrationFrom ? counter.registrationFrom.sql() : '',
                registrationTo: counter.registrationTo ? counter.registrationTo.sql() : '',
            };
        },

        async saveConfig(item) {
            const counter = item.counterEntity;
            if (!counter) {
                return;
            }
            const payload = this.payload(item);
            this.lastSaved = JSON.stringify(payload);
            item.saving = true;

            try {
                const data = await counter.invoke('saveCounterAppealConfig', payload);
                item.formFieldsCount = data.formFieldsCount;
                item.appealPhase.judgementLocked = !!data.judgementLocked;
                item.saved = true;
                this.messages.success(this.text('Modificações salvas'));
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
                item.form = this.formFrom(null);
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
