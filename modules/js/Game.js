const BgaAnimations = await importEsmLib('bga-animations', '1.x');
const BgaCards = await importEsmLib('bga-cards', '1.x');

class GameOn {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        this.onPlayerActivationChange(args, isCurrentPlayerActive);
    }

    onPlayerActivationChange(args, isCurrentPlayerActive) {
        this.game.setPlayOptions();
    }
}

class NewHand {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        this.onPlayerActivationChange(args, isCurrentPlayerActive);
    }

    onPlayerActivationChange(args, isCurrentPlayerActive) {
        this.game.setPlayOptions();
    }
}

class NextPlayer {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        this.onPlayerActivationChange(args, isCurrentPlayerActive);
    }

    onPlayerActivationChange(args, isCurrentPlayerActive) {
        this.game.setPlayOptions();
    }
}

class PickRank {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onPlayerActivationChange(args, isCurrentPlayerActive) {
        this.game.pickRank(this.game.fw_player_id, 'TODO fix');
    }
}

class PickSuit {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onPlayerActivationChange(args, isCurrentPlayerActive) {
        this.game.pickSuit(this.game.fw_player_id, 'TODO fix');
    }
}

export class Game {
    constructor(bga) {
        console.log('makaucloudnein constructor');
        this.bga = bga;

        this.bga.states.register('NewHand', new NewHand(this, bga));
        this.bga.states.register('GameOn', new GameOn(this, bga));
        this.bga.states.register('PickRank', new PickRank(this, bga));
        this.bga.states.register('PickSuit', new PickSuit(this, bga));

        this.bga.states.logger = console.log;
        this.skip = 0;
        this.draw = 0;

        this.drew = 0;
        this.card_types = {
            "suit": {
                1: "Spade",
                2: "Heart",
                3: "Club",
                4: "Diamond",
            },
            "suit_unicode": {
                1: "&#9824;",
                2: "&#9829;",
                3: "&#9827;",
                4: "&#9830;",
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
                14: "Ace",
                15: "Joker"
            },
        }
    }

    tooltip(card) {
        let r = `${this.card_types["rank"][card.rank]} ${this.card_types["suit_unicode"][card.suit]}`;
        switch (card.rank) {
            case 2:
                r += ' (next player draws 2)';
                break;
            case 3:
                r += ' (next player draws 3)';
                break;
            case 4:
                r += ' (skip next player)';
                break;
            case 11:
                r += ' (demand non-action rank of other players)';
                break;
            case 12:
                r += ' (anything on queen, queen on anything)';
                break;
            case 14:
                r += ' (demand next player play suit)';
                break;
            case 15:
                r += ' (can be played as any card at any time)';
                break;
        }

        return r;
    }

    setup(args) {
        console.log("Starting game setup");
        this.fw_player_id = args.fw_player_id;
        this.bw_player_id = args.bw_player_id;
        this.player_ids = args.player_ids;
        this.player_id = args.player_id;
        this.skip = args.skip;
        this.draw = args.draw;
        this[`draw_{player_id}`] = args[`draw_{player_id}`];
        this[`skip_{player_id}`] = args[`skip_{player_id}`];
        this.rank_demand = args.rank_demand;
        this.last_jack = args.last_jack;
        this.drew = args.drew;

        this.bga.gameArea.getElement().insertAdjacentHTML(
            "beforeend",
            `
                    <div id="table" class="table" height="200px">
                        <div id="otherstuff">
                            <div id="deck_block" bgcolor="green">
                                <b id="deck_label">${_("Deck")}</b>
                                <div id="deck"></div>
                            </div>
                            <div id="discard_block" bgcolor="yellow">
                                <b align="left" id="discard_label">${_("Discard pile")}</b>
                                <div id="discard"></div>
                            </div>
                            <div id="play_block">
                                <b id="play_label"></b>
                                <div id="play"></div>
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
                        <div id="hand_${player_id}" class="player_block">
                            <b id="hand_${player_id}_label">${this.bga.players.getPlayerById(player_id).name}'s hand</b>
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
                this.bga.gameui.addTooltipHtml(div.id, this.tooltip(card));
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

            this[`hand_${player_id}`] = new BgaCards.HandStock(
                this.cardsManager,
                document.getElementById(`hand_${player_id}`),
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

        this.play = new BgaCards.HandStock(
            this.cardsManager,
            document.getElementById("play"),
            {
                fanShaped: false,
                cardOverlap: 75
            }
        );

        this.play.setSelectionMode('single');

        this.discard = new BgaCards.HandStock(
            this.cardsManager,
            document.getElementById("discard"),
            {
                fanShaped: false,
                cardOverlap: 75
            }
        );

        this.discard.addCards(args.discards);

        this.hand.onCardClick = (card) => {
            if (args.gamestate.name != "GameOn") this.hand.unselectAll();
            this.play.addCard(card);
            this.setPlayOptions();
        }

        this.play.onCardClick = (card) => {
            this.hand.addCard(card);
            this.setPlayOptions();
        }

        // Setup game notifications to handle (see "setupNotifications" method below)
        this.setupNotifications();

        console.log("Ending game setup");
    }

    buttonsPlay() {
        this.playButton = this.bga.statusBar.addActionButton(_('Play'), () => {
            this.bga.actions.performAction(
                'actPlay', {
                cardIds: this.play.getCards().map((card) => card.id)
            });
        });
    }

    buttonsDraw() {
        this.drawButton = this.bga.statusBar.addActionButton(_('Draw'), () => {
            this.hand.addCards(this.play.getCards());
            this.bga.actions.performAction('actDraw');
        });
    }

    buttonsPass() {
        this.passButton = this.bga.statusBar.addActionButton(_('Pass'), () => {
            this.hand.addCards(this.play.getCards());
            this.bga.actions.performAction('actPass');
        });
    }

    buttonsMakau() {
        this.makauButton = this.bga.statusBar.addActionButton(_('Makau'), () => this.bga.actions.performAction('actMakau'));
    }

    // Should match getPlayableCards in States/GameOn.php
    getPlayableCards(top, cards) {
        let matchingCards = [];

        for (let i = 0; i < cards.length; i++) {
            let card = cards[i];
            let playable = false;
            if (this.skip > 0 && card.rank == 4)
                playable = true;

            if (this.draw > 0 &&
                (
                    card.rank == 2
                    || card.rank == 3
                    || card.rank == 13 // TODO: king
                )
            )
                playable = true;

            if (this.rank_demand > 0
                && (
                    card.rank == this.rank_demand
                    || card.rank === 11
                )
            )
                playable = true;

            if (this.skip == 0
                && this.draw == 0
                && this.rank_demand == 0
                && (
                    card.rank == 12 // queen
                    || card.suit == top.suit
                    || card.rank == top.rank
                    || top.rank == 12 // queen
                )
            )
                playable = true;

            if (playable)
                matchingCards.push(card);
        }

        return matchingCards;
    }

    setPlayOptions() {
        let currentPlayerId = this.bga.players.getCurrentPlayerId();
        this.bga.statusBar.removeActionButtons();

        let handCards = this.hand.getCards();
        let topCards = this.play.getCards();
        if (topCards.length == 0) topCards = this.discard.getCards();
        let top = topCards[topCards.length - 1];

        let skip = this[`skip_{currentPlayerId}`];
        let draw = this[`draw_{currentPlayerId}`];

        if (this.player_id == this.last_jack) {
            this.last_jack = 0; this.rank_demand = 0;
        }

        let playableCards = this.getPlayableCards(top, handCards);

        if (currentPlayerId == this.fw_player_id) {
            if (this[`skip_{this.player_id}`] > 0) {
                this.bga.statusBar.setTitle(_('You must skip your turn.'));
                this.buttonsPass();
                playableCards = [];
            }

            if (this[`draw_{this.player_id}`] > 0 || this.draw && playableCards.length < 1) {
                this.bga.statusBar.setTitle(_('You must draw cards.'));
                this.buttonsDraw();
                playableCards = [];
            } else {
                if (this.play.getCards().length < 1) {
                    if (playableCards.length < 1) {
                        this.bga.statusBar.setTitle(_('You have no playable cards.'));
                        if (!this.drew) this.buttonsDraw();
                        this.buttonsPass();
                    } else {
                        this.bga.statusBar.setTitle(_('Pick cards to play.'));
                        this.buttonsPlay();
                        if (!this.drew) this.buttonsDraw();
                        this.buttonsPass();

                    }
                } else {
                    this.bga.statusBar.setTitle(_('Click play when you\'re done...'));
                    this.buttonsPlay();
                    if (!this.drew) this.buttonsDraw();
                    this.buttonsPass();
                }
            }
        } else if (currentPlayerId == this.bw_player_id) {
            // TODO: bw_active_player
        } else {
            this.draw = 0;
            this.skip = 0;
            playableCards = [];

            this.buttonsMakau();
            this.bga.statusBar.setTitle(_("${active_player} is playing now."), {
                "active_player": this.bga.players.getPlayerById(this.fw_player_id).name,
            });
        }

        this.hand.setSelectionMode('single', playableCards);
    }

    setupNotifications() {
        console.log('notifications subscriptions setup');

        this.bga.notifications.setupPromiseNotifications({
            // logger: console.log
        });
    }

    updateOtherPlayerHandCount(args) {
        for (let i = 0; i < this.player_ids.length; i++) {
            let player_id = this.player_ids[i];
            if (args[`hand_${player_id}`] == undefined || this[`hand_${player_id}`] == undefined) continue;

            this[`hand_${player_id}`].removeAll();

            for (let j = 0; j < args[`hand_${player_id}`]; j++) {
                this[`hand_${player_id}`].addCard({
                    id: `player_${player_id}_card_${j}`,
                    suit: 0,
                    rank: 0
                });
            }
        }
    }

    pickRank(fw_player_id, player_name) {
        this.bga.statusBar.removeActionButtons();

        if (this.player_id == fw_player_id) {
            let cards = this.hand.cards;

            this.bga.statusBar.setTitle('Select a rank.');
            this.rankButtons = [];
            let availableRanks = [];
            availableRanks['any'] = 15; // TODO: get to work!
            for (let i = 0; i < cards.length; i++) {
                let card = cards[i];
                if (card.rank > 4 && card.rank < 11) {
                    availableRanks[card.rank] = card.rank;
                }
            }

            for (let i = 5; i < 11; i++) {
                if (availableRanks[i] == undefined) continue;
                this.rankButtons[i] = this.bga.statusBar.addActionButton(_(`${i}`), () => {
                    this.bga.actions.performAction(
                        'actPick', {
                        rank: i
                    });
                });
            }
        } else {
            this.bga.statusBar.setTitle(`${player_name} is selecting a rank.`);
        }
    }

    pickSuit(fw_player_id, player_name) {
        debugger;
        this.bga.statusBar.removeActionButtons();

        if (this.player_id == fw_player_id) {
            let cards = this.hand.cards;

            this.bga.statusBar.setTitle('Select a suit.');
            this.suitButtons = [];
            let availableSuits = [];
            availableSuits['any'] = 15; // TODO: get to work!
            for (let i = 0; i < cards.length; i++) {
                let card = cards[i];
                availableSuits[card.suit] = card.suit;
            }

            for (let i = 1; i < 5; i++) {
                if (availableSuits[i] == undefined) continue;
                // TODO: show emoji of the suit
                this.suitButtons[i] = this.bga.statusBar.addActionButton(_(`${this.card_types["suit_unicode"][i]}`), () => {
                    this.bga.actions.performAction(
                        'actPick', {
                        suit: i
                    });
                });
            }
        } else {
            this.bga.statusBar.setTitle(`${player_name} is selecting a suit.`);
        }
    }

    async notif_NewHand(args) {
        // We received a new full hand of cards.
        await this.hand.removeAll();
        await this.hand.addCards(Array.from(Object.values(args.hand)));
        await this.discard.removeAll();
        await this.discard.addCards(Array.from(Object.values(args.discards)));
        this.fw_player_id = args.fw_player_id;
        this.bw_player_id = 0;
        this.skip = 0;
        this.draw = 0;
        this.drew = 0;
        this.deck.setCardNumber(args.deck);

        this.updateOtherPlayerHandCount(args);
        this.setPlayOptions();
    }

    async notif_Deal(args) {
        if (args._private) {
            await this.hand.addCards(args._private.cards);
        }
        this.drew = 0;
        this.draw = 0;
        this.deck.setCardNumber(args.deck);

        this.updateOtherPlayerHandCount(args);
        this.setPlayOptions();
    }

    async notif_Pass(args) {
        this.suit_demand = 0;
        this[`skip_${args.player_id}`] = args.skip;
    }

    async notif_DrawCards(args) {
        this.deck.setCardNumber(args.deck);
        if (args._private) {
            await this.hand.addCards(Array.from(Object.values(args._private.cards)));
            this.drew = 1;
            this.setPlayOptions();
        } else {
            this.updateOtherPlayerHandCount(args);
        }
        this.draw = 0;
    }

    async notif_NextPlayer(args) {
        this.fw_player_id = args.fw_player_id;
        this.drew = 0;
        this.setPlayOptions();
    }

    async notif_PlayCards(args) {
        this.discard.addCards(Array.from(Object.values(args.cards)));

        this.draw = args.draw;
        this.skip = args.skip;
        this.suit_demand = args.suit_demand;
        this.rank_demand = args.rank_demand;
        this.last_jack = args.last_jack;

        this.updateOtherPlayerHandCount(args);
    }

    async notif_PickRank(args) {
        this.pickRank(args.fw_player_id, args.player_name);
    }

    async notif_RankDemand(args) {
        this.rank_demand = args.rank_demand;
        this.last_jack = args.last_jack;
    }

    async notif_PickSuit(args) {
        this.pickSuit(args.fw_player_id, args.player_name);
    }

    async notif_SuitDemand(args) {
        this.suit_demand = args.suit_demand;
    }

    async notif_InvalidPlay(args) {
        this.hand.unselectAll();
    }
}