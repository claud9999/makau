const BgaAnimations = await importEsmLib('bga-animations', '1.x');
const BgaCards = await importEsmLib('bga-cards', '1.x');

class PlayerTurn {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    getRankMatches(cardToMatch, cards) {
        let matchcards = [];
        for (let i = 0; i < cards.length; i++) {
            let card = cards[i];
            if (card.type_arg == cardToMatch.type_arg) {
                matchcards.push(card);
            }
        }
        return matchcards;
    }

    onEnteringState(_args, isCurrentPlayerActive) {
        this.game.hand.setSelectionMode('none');
        debugger;

        this.game.playButton = this.bga.statusBar.addActionButton(_('Play'), () =>
            this.bga.actions.performAction('actPlay', { cards: this.game.hand.selectedCards.map((card) => card.id) }));

        this.game.drawButton = this.bga.statusBar.addActionButton(_('Draw'), () => this.bga.actions.performAction('actDraw'));

        this.game.skipButton = this.bga.statusBar.addActionButton(_('Skip'), () => this.bga.actions.performAction('actSkip'));

        if (isCurrentPlayerActive) this.game.setPlayOptions();
        else {
            this.game.playButton.disabled = true;
            this.game.drawButton.disabled = true;
            this.game.skipButton.disabled = true;

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
        this.playButton = null;
        this.drawButton = null;
        this.skipButton = null;
    }

    setup(gamedatas) {
        console.log("Starting game setup");
        this.gamedatas = gamedatas;

        this.bga.gameArea.getElement().insertAdjacentHTML(
            "beforeend",
            `
                    <div id="table" class="table" height="200px">
                    <div id="deck"></div>
                    <div id="discard" bgcolor="yellow"></div>
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

        this.discard = new BgaCards.DiscardDeck(
            this.cardsManager,
            document.getElementById("discard")
        );

        this.hand.addCards(Array.from(Object.values(this.gamedatas.hand)));
        this.discard.addCards(Array.from(Object.values(this.gamedatas.discard)));

        this.hand.onCardClick = (card) => {
            if (this.gamedatas.gamestate.name != "PlayerTurn") this.hand.unselectAll();
        };
        // Setup game notifications to handle (see "setupNotifications" method below)
        this.setupNotifications();

        this.skipcount = this.gamedatas.skipcount;
        this.drawcount = this.gamedatas.drawcount;

        console.log("Ending game setup");
    }


    canPlay(selectableCards) {
        this.bga.statusBar.setTitle(_('It\'s your turn...'));

        this.playButton.disabled = false;
        this.drawButton.disabled = false;

        this.hand.setSelectionMode('multiple', selectableCards);
    }

    setPlayOptions() {
        let handCards = this.hand.getCards();
        let discards = this.discard.getCards();
        let topDiscard = discards[discards.length - 1];

        this.skipButton.disabled = true;
        this.playButton.disabled = true;
        this.drawButton.disabled = true;

        if (this.skipcount > 0) {
            let selectablecards = this.getRankMatches(topDiscard, handCards);

            if (selectablecards.length < 1) {
                this.bga.statusBar.setTitle(_('You have no playable cards and must skip your turn'));
                this.skipButton.disabled = false;
            } else {
                this.canPlay(selectablecards);
            }
        } else if (this.drawcount > 0) {
            let selectablecards = this.getRankMatches(topDiscard, handCards);

            if (selectablecards.length < 1) {
                this.bga.statusBar.setTitle(_('You have no playable cards and must draw a card'));
                this.drawButton.disabled = false;
            } else {
                this.canPlay(selectablecards);
            }
        } else {
            let selectablecards = [];
            for (let i = 0; i < handCards.length; i++) {
                let card = handCards[i];
                if (card.type_arg == topDiscard.type_arg || card.type == topDiscard.type || card.type_arg == 12) {
                    selectablecards.push(card);
                }
            }
            if (selectablecards.length < 1) {
                this.bga.statusBar.setTitle(_('You have no playable cards'));
                this.drawButton.disabled = false;
            } else {
                this.canPlay(selectablecards);
            }
        }
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

    async notif_drawCard(args) {
        if (args._private) {
            this.hand.addCard(args._private.card);
            this.setPlayOptions();
        }
    }

    async notif_playCard(args) {
        this.discard.addCards([args.card]);
    }

    async notif_invalidPlay(args) {
        this.hand.unselectAll();
    }
}