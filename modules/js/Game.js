const BgaAnimations = await importEsmLib('bga-animations', '1.x');
const BgaCards = await importEsmLib('bga-cards', '1.x');

class GameOn {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(_args, isCurrentPlayerActive) {
        this.game.playButton = this.bga.statusBar.addActionButton(_('Play'), () =>
            this.bga.actions.performAction('actPlay', { cardIds: this.game.hand.selectedCards.map((card) => card.id) }));
        this.game.drawButton = this.bga.statusBar.addActionButton(_('Draw'), () => this.bga.actions.performAction('actDraw'));
        this.game.passButton = this.bga.statusBar.addActionButton(_('Pass'), () => {
            this.bga.actions.performAction('actPass')
        });
        this.game.makauButton = this.bga.statusBar.addActionButton(_('Makau'), () => this.bga.actions.performAction('actMakau'));

        this.game.setPlayOptions();
    }
}

class NewHand {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }
}


export class Game {
    constructor(bga) {
        console.log('makaucloudnein constructor');
        this.bga = bga;

        this.GameOn = new GameOn(this, bga);
        this.bga.states.register('NewHand', new NewHand(this, bga));
        this.bga.states.register('GameOn', new GameOn(this, bga));

        this.bga.states.logger = console.log;
        this.drew = 0;
        this.card_types = {
            "suit": {
                1: "Spade",
                2: "Heart",
                3: "Club",
                4: "Diamond",
            },
            "rank": {
                2: "Two",
                3: "Three",
                4: "Four",
                5: "Five",
                6: "Six",
                7: "Seven",
                8: "Eight",
                9: "Nine",
                10: "Ten",
                11: "Jack",
                12: "Queen",
                13: "King",
                14: "Joker"
            },
        }
    }

    setup(args) {
        console.log("Starting game setup");
        this.active_player_no = args.active_player_no;
        this.skip_count = args.skip_count;
        this.draw_count = args.draw_count;
        this.drew = args.drew;

        this.bga.gameArea.getElement().insertAdjacentHTML(
            "beforeend",
            `
                    <div id="table" class="table" height="200px">
                        <div id="otherstuff">
                            <div id="deck">
                                <b id="deck_label">${_("Deck")}</b>
                            </div>
                            <div id="discard" bgcolor="yellow">
                                <b id="discard_label">${_("Discard pile")}</b>
                            </div>
                        </div>
                        <div id="otherplayers" class="player_blocks">
            `
        );

        for (let i = 0; i < args.player_ids.length; i++) {
            let player_id = args.player_ids[i];
            if (player_id == this.bga.players.getCurrentPlayerId()) continue;

            this.bga.gameArea.getElement().insertAdjacentHTML(
                "beforeend",
                `
                        <div id="player_${player_id}_hand" class="player_block">
                            <b id="player_${player_id}_hand_label">${this.bga.players.getPlayerById(player_id).name}'s hand</b>
                        </div>
                `
            );
        }


        // close the "otherplayers" and "table" tags
        this.bga.gameArea.getElement().insertAdjacentHTML(
            "beforeend",
            `
                    </div><!-- close otherplayers -->
                    <div id="hand_block">
                        <b id="hand_label">${_("My hand")}</b>
                        <div id="hand"></div>
                    </div>
                </div><!-- close table -->
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
                this.bga.gameui.addTooltipHtml(div.id, `${this.card_types["rank"][card.rank]} of ${this.card_types["suit"][card.suit]}s`);
            },
            setupBackDiv: (card, div) => {
                div.style.backgroundPositionX = `100%`;
                div.style.backgroundPositionY = `0%`;
            },
            isCardVisible: (card) => { return card.rank > 0; }
        });

        this.deck = new BgaCards.Deck(this.cardsManager, document.getElementById('deck'), {
            cardNumber: args.deck,
            counter: {
                position: 'center',
                extraClasses: 'text-shadow'
            }
        });

        for (let i = 0; i < args.player_ids.length; i++) {
            let player_id = args.player_ids[i];
            if (player_id == this.bga.players.getCurrentPlayerId()) continue;

            this["player_" + player_id + "_hand"] = new BgaCards.HandStock(
                this.cardsManager,
                document.getElementById("player_" + player_id + "_hand"),
                {
                    cardOverlap: 75
                }
            );

            this.updateOtherPlayerHandCount(args);
        }

        this.hand = new BgaCards.HandStock(
            this.cardsManager,
            document.getElementById("hand")
        );
        this.hand.addCards(args.hand);

        this.discard = new BgaCards.HandStock(
            this.cardsManager,
            document.getElementById("discard"),
            {
                fanShaped: false,
            }
        );

        this.discard.addCards(args.discards);

        this.hand.onCardClick = (card) => {
            if (args.gamestate.name != "GameOn") this.hand.unselectAll();
        };

        // Setup game notifications to handle (see "setupNotifications" method below)
        this.setupNotifications();

        console.log("Ending game setup");
    }

    // Should match getPlayableCards in Game.php
    getPlayableCards(top, cards) {
        let matchingCards = [];

        for (let i = 0; i < cards.length; i++) {
            let card = cards[i];
            if (this.skip_count > 0) {
                if (card.rank == 4)
                    matchingCards.push(card);
            } else if (this.draw_count > 0) {
                if (card.rank == 2
                    || card.rank == 3
                    || card.rank == 13 // king
                )
                    matchingCards.push(card);
            } else {
                if (card.rank == 12 // queen
                    || card.suit == cardToMatch.suit
                    || card.rank == cardToMatch.rank
                    || top.rank == 12 // queen
                )
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
        this.makauButton.disabled = true; // TODO: compute and enable only when it can be said

        if (this.bga.players.getCurrentPlayerNo() == this.active_player_no) {
            let selectableCards = [];
            if (this.skip_count > 0) {
                selectableCards = this.getPlayableCards(topDiscard, handCards);

                if (selectableCards.length < 1) {
                    this.bga.statusBar.setTitle(_('You must skip your turn.'));
                    this.hand.setSelectionMode('none');
                    this.passButton.disabled = false;
                } else {
                    this.bga.statusBar.setTitle(_('You can play a skip card.'));

                    this.playButton.disabled = false;
                    this.drawButton.disabled = true;

                    this.hand.setSelectionMode('multiple', selectableCards);
                }
            } else if (this.draw_count > 0) {
                selectableCards = this.getPlayableCards(topDiscard, handCards);

                if (selectableCards.length < 1) {
                    this.bga.statusBar.setTitle(_('You must draw a card.'));
                    this.hand.setSelectionMode('none');
                    this.drawButton.disabled = false;
                } else {
                    this.bga.statusBar.setTitle(_('You can add to the draw.'));

                    this.playButton.disabled = false;
                    this.drawButton.disabled = true;

                    this.hand.setSelectionMode('multiple', selectableCards);
                }
            } else {
                this.drawButton.disabled = this.drew;
                this.passButton.disabled = !this.drew;
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
                    this.bga.statusBar.setTitle(_('It\'s your turn...'));

                    this.playButton.disabled = false;

                    this.hand.setSelectionMode('multiple', selectableCards);
                }
            }
        } else {
            this.hand.setSelectionMode('none');

            this.bga.statusBar.setTitle(_("${active_player_no} is playing now."), {
                "active_player_no": this.bga.players.getPlayerByNo(this.active_player_no).name,
            });
        }
    }

    setupNotifications() {
        console.log('notifications subscriptions setup');

        this.bga.notifications.setupPromiseNotifications({
            // logger: console.log
        });
    }

    updateOtherPlayerHandCount(args) {
        for (let i = 0; i < args.player_ids.length; i++) {
            let player_id = args.player_ids[i];
            if (player_id == this.bga.players.getCurrentPlayerId()) continue;

            this["player_" + player_id + "_hand"].removeAll();

            for (let j = 0; j < args["player_" + player_id + "_hand"]; j++) {
                this["player_" + player_id + "_hand"].addCard({
                    id: "player_" + player_id + "_card_" + j,
                    suit: 0,
                    rank: 0
                });
            }
        }
    }

    async notif_NewHand(args) {
        // We received a new full hand of cards.
        await this.hand.removeAll();
        await this.hand.addCards(Array.from(Object.values(args.hand)));
        await this.discard.addCards(Array.from(Object.values(args.discards)));
        this.active_player_no = args.active_player_no;
        this.skip_count = 0;
        this.draw_count = 0;
        this.drew = 0;
        this.deck.setCardNumber(args.deck);

        this.updateOtherPlayerHandCount(args);
        this.setPlayOptions();
    }

    async notif_DrawCards(args) {
        if (args._private) {
            await this.hand.addCards(args._private.cards);
        }
        this.drew = 1;
        this.draw_count = 0;
        this.deck.setCardNumber(args.deck);

        this.updateOtherPlayerHandCount(args);
        this.setPlayOptions();
    }

    async notif_Pass(args) {
        this.skip_count = 0;
    }

    async notif_NextPlayer(args) {
        this.active_player_no = args.active_player_no;
        this.drew = 0;
    }

    async notif_PlayCards(args) {
        this.discard.addCards(Array.from(Object.values(args.cards)));
        this.updateOtherPlayerHandCount(args);
        this.skip_count = args.skip_count;
        this.draw_count = args.draw_count;
    }

    async notif_InvalidPlay(args) {
        this.hand.unselectAll();
    }
}