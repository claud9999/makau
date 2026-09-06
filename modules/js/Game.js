const BgaAnimations = await importEsmLib('bga-animations', '1.x');
const BgaCards = await importEsmLib('bga-cards', '1.x');

class GameOn {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(_args, isCurrentPlayerActive) {
        this.game.playButton = this.bga.statusBar.addActionButton(_('Play'), () =>
            this.bga.actions.performAction('actPlay', { cards: this.game.hand.selectedCards.map((card) => card.id) }));
        this.game.drawButton = this.bga.statusBar.addActionButton(_('Draw'), () => this.bga.actions.performAction('actDraw'));
        this.game.passButton = this.bga.statusBar.addActionButton(_('Pass'), () => this.bga.actions.performAction('actPass'));
        this.game.makauButton = this.bga.statusBar.addActionButton(_('Makau'), () => this.bga.actions.performAction('actMakau'));

        this.game.setPlayOptions();
    }

    onLeavingState(args, isCurrentPlayerActive) { }

    onPlayerActivationChange(args, isCurrentPlayerActive) { }
}

class NewHand {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(_args, isCurrentPlayerActive) {
    }

    onLeavingState(args, isCurrentPlayerActive) { }

    onPlayerActivationChange(args, isCurrentPlayerActive) { }
}


export class Game {
    constructor(bga) {
        console.log('makaucloudnein constructor');
        this.bga = bga;

        this.GameOn = new GameOn(this, bga);
        this.bga.states.register('NewHand', new NewHand(this, bga));
        this.bga.states.register('GameOn', new GameOn(this, bga));

        this.bga.states.logger = console.log;
    }

    setup(gamedatas) {
        console.log("Starting game setup");
        this.active = gamedatas.active;
        this.skipCount = gamedatas.skipcount;
        this.drawCount = gamedatas.drawcount;

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
            `
        );

        // create the animation manager, and bind it to the `game.bgaAnimationsActive()` function
        this.animationManager = new BgaAnimations.Manager({
            animationsActive: () => this.bga.gameui.bgaAnimationsActive()
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
                div.dataset.suit = card.suit; // 1..4
                div.dataset.rank = card.rank; // value 2..14
                div.style.backgroundPositionX = `calc(100% / 14 * (${card.rank} - 2))`; // 14 is number of columns in stock image minus 1
                div.style.backgroundPositionY = `calc(100% / 3 * (${card.suit} - 1))`; // 3 is number of rows in stock image minus 1
                this.bga.gameui.addTooltipHtml(div.id, `${card.rank} of ${card.suit}`);
            },
            setupBackDiv: (card, div) => {
                div.style.backgroundPositionX = `100%`;
                div.style.backgroundPositionY = `0%`;
            },
            isCardVisible: (card) => { return card.rank > 0; }
        });

        this.deck = new BgaCards.Deck(this.cardsManager, document.getElementById('deck'), {
            cardNumber: gamedatas.deck,
            counter: {
                position: 'center',
                extraClasses: 'text-shadow'
            }
        });

        this.hand = new BgaCards.HandStock(
            this.cardsManager,
            document.getElementById("hand")
        );
        this.hand.addCards(gamedatas.hand);

        this.discard = new BgaCards.LineStock(
            this.cardsManager,
            document.getElementById("discard")
        );
        this.discard.addCards(gamedatas.discard);

        this.hand.onCardClick = (card) => {
            if (gamedatas.gamestate.name != "GameOn") this.hand.unselectAll();
        };

        // Setup game notifications to handle (see "setupNotifications" method below)
        this.setupNotifications();

        console.log("Ending game setup");
    }

    canPlay(selectableCards) {
        this.bga.statusBar.setTitle(_('It\'s your turn...'));

        this.playButton.disabled = false;
        this.drawButton.disabled = false;

        this.hand.setSelectionMode('multiple', selectableCards);
    }

    getPlayableCards(cardToMatch, cards) {
        let matchingCards = [];
        for (let i = 0; i < cards.length; i++) {
            let card = cards[i];
            if (card.type_arg == cardToMatch.type_arg) {
                matchingCards.push(card);
            }
        }

        return matchingCards;
    }

    setPlayOptions() {
        let handCards = this.hand.getCards();
        let discards = this.discard.getCards();
        let topDiscard = discards[discards.length - 1];

        this.passButton.disabled = true;
        this.playButton.disabled = true;
        this.drawButton.disabled = true;
        this.makauButton.disabled = false;

        if (this.bga.players.getCurrentPlayerNo() == this.active) {
            let selectableCards = [];
            if (this.skipCount > 0) {
                selectableCards = this.getPlayableCards(topDiscard, handCards);

                if (selectableCards.length < 1) {
                    this.bga.statusBar.setTitle(_('You must skip your turn'));
                    this.hand.setSelectionMode('none');
                    this.passButton.disabled = false;
                } else {
                    this.canPlay(selectableCards);
                }
            } else if (this.drawCount > 0) {
                selectableCards = this.getPlayableCards(topDiscard, handCards);

                if (selectableCards.length < 1) {
                    this.bga.statusBar.setTitle(_('You must draw a card'));
                    this.hand.setSelectionMode('none');
                    this.drawButton.disabled = false;
                } else {
                    this.canPlay(selectableCards);
                }
            } else {
                for (let i = 0; i < handCards.length; i++) {
                    let card = handCards[i];
                    if (card.suit == topDiscard.suit || card.rank == topDiscard.rank || card.rank == 12 || topDiscard.rank == 12) {
                        selectableCards.push(card);
                    }
                }
                if (selectableCards.length < 1) {
                    this.bga.statusBar.setTitle(_('You have no playable cards'));
                    this.hand.setSelectionMode('none');
                } else {
                    this.canPlay(selectableCards);
                }
            }
        } else {
            this.hand.setSelectionMode('none');

            this.bga.statusBar.setTitle(_("${active} is playing now."), {
                "active": this.bga.players.getPlayerByNo(this.active).name,
            });
        }
    }

    setupNotifications() {
        console.log('notifications subscriptions setup');

        this.bga.notifications.setupPromiseNotifications({
            // logger: console.log
        });
    }

    async notif_NewHand(args) {
        // We received a new full hand of cards.
        await this.hand.removeAll();
        await this.hand.addCards(Array.from(Object.values(args.hand)));
        await this.discard.addCards(Array.from(Object.values(args.discard)));
        this.active = args.active;
        this.skipCount = 0;
        this.drawCount = 0;
        this.deck.setCardNumber(args.deck);

        this.setPlayOptions();
    }

    async notif_DrawCard(args) {
        if (args._private) {
            await this.hand.addCard(args._private.card);
        }
    }

    async notif_NextPlayer(args) {
        this.active = args.active;
    }

    async notif_PlayCards(args) {
        this.discard.addCards(Array.from(Object.values(args.cards)));
    }

    async notif_InvalidPlay(args) {
        this.hand.unselectAll();
    }
}