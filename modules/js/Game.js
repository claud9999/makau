const BgaAnimations = await importEsmLib('bga-animations', '1.x');
const BgaCards = await importEsmLib('bga-cards', '1.x');

class PlayerTurn {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(_args, isCurrentPlayerActive) {
        if (isCurrentPlayerActive) {
            this.game.hand.setSelectionMode('multiple');
            this.bga.statusBar.setTitle(_('It\'s your turn...'));

            this.bga.statusBar.addActionButton(_('Play'), () => {
                debugger;
                this.bga.actions.performAction('actPlay', { cards: this.game.hand.selectedCardIds });
            });

            this.bga.statusBar.addActionButton(_('Draw'), () => this.bga.actions.performAction('actDraw'));

            this.bga.statusBar.addActionButton(_('Pass'), () => this.bga.actions.performAction('actPass'));
        } else {
            this.game.hand.setSelectionMode('none');
            this.bga.statusBar.setTitle(_('It is ${actplayer}\'s turn'));
        }
    }

    onLeavingState(args, isCurrentPlayerActive) { }

    onPlayerActivationChange(args, isCurrentPlayerActive) { }
}

export class Game {
    constructor(bga) {
        console.log('makaucloudnein constructor');
        this.bga = bga;

        this.playerTurn = new PlayerTurn(this, bga);
        this.bga.states.register('PlayerTurn', this.playerTurn);

        this.bga.states.logger = console.log;
    }

    setup(gamedatas) {
        console.log("Starting game setup");
        this.gamedatas = gamedatas;

        this.bga.gameArea.getElement().insertAdjacentHTML(
            "beforeend",
            `
                    <div id="table" class="table">
                    <span id="deck"></span>
                    <span id="discards" bgcolor="yellow"></span>
                    <span id="discard_button" onClick="discardCard()">Discard</span>
                    </div>
                    <div id="hand_wrap" class="whiteblock">
                        <b id="hand_label">${_("My hand")}</b>
                        <div id="hand"></div>
                    </div>

            `,
        );

        // create the animation manager, and bind it to the `game.bgaAnimationsActive()` function
        this.animationManager = new BgaAnimations.Manager({
            animationsActive: () => this.bga.gameui.bgaAnimationsActive(),
        });

        const cardWidth = 100;
        const cardHeight = 135;

        this.cardsManager = new BgaCards.Manager({
            animationManager: this.animationManager,
            type: "ha-card",
            getId: (card) => card.id,

            cardWidth: cardWidth,
            cardHeight: cardHeight,
            cardBorderRadius: "5%",
            setupFrontDiv: (card, div) => {
                div.dataset.type = card.type; // suit 1..4
                div.dataset.typeArg = card.type_arg; // value 2..14
                div.style.backgroundPositionX = `calc(100% / 14 * (${card.type_arg} - 2))`; // 14 is number of columns in stock image minus 1
                div.style.backgroundPositionY = `calc(100% / 3 * (${card.type} - 1))`; // 3 is number of rows in stock image minus 1
                this.bga.gameui.addTooltipHtml(div.id, `tooltip of ${card.type}`);
            },
            setupBackDiv: (card, div) => {
                div.style.backgroundPositionX = `100%`;
                div.style.backgroundPositionY = `0%`;
            }
        });

        this.deck = new BgaCards.Deck(this.cardsManager, document.getElementById('deck'), {
            cardNumber: this.gamedatas.deck,
            counter: {
                position: 'center',
                extraClasses: 'text-shadow',
            }
        });

        this.hand = new BgaCards.HandStock(
            this.cardsManager,
            document.getElementById("hand")
        );

        this.discards = new BgaCards.LineStock(
            this.cardsManager,
            document.getElementById("discards")
        );

        this.hand.addCards(Array.from(Object.values(this.gamedatas.hand)));
        this.discards.addCards(Array.from(Object.values(this.gamedatas.discards)));

        this.deck.onCardClick = (card) => {
            debugger;
            console.log('Deck card clicked: ', card);
        };

        this.hand.onCardClick = (card) => {
            if (this.gamedatas.gamestate.name !== "PlayerTurn") this.hand.unselectAll();
        };
        // Setup game notifications to handle (see "setupNotifications" method below)
        this.setupNotifications();

        console.log("Ending game setup");
    }

    setupNotifications() {
        console.log('notifications subscriptions setup');

        this.bga.notifications.setupPromiseNotifications({
            // logger: console.log
        });
    }

    async notif_newHand(args) {
        // We received a new full hand of cards.
        this.hand.removeAll();
        this.hand.addCards(Array.from(Object.values(args.hand)));
    }

    async notif_playCard(args) {
        // Play a card on the table
        debugger;
        this.discards.addCards([args.card]);
    }
}