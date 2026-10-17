<?php

declare(strict_types=1);

namespace Bga\Games\makaucloudnein\States;

use Bga\Games\makaucloudnein\States\NextPlayer;

use Bga\Games\makaucloudnein\Game;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\Actions\CheckAction;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\Actions\Types\IntArrayParam;
use Bga\GameFramework\Actions\Types\JsonParam;

/*
  Any condition that results in a call to $game->err() is
  unexpected. The client UI should never produce conditions
  that would trigger these. Hence these messages are not translated.

  All state information is stored in a state variable ($st)
  and saved via the globals interface in the database as
  JSON. The state is usually also sent to the client in
  toto even though the client likely only needs a few
  fields as it makes the code simpler.

  For act* functions, the general pattern is that it loads
  the state from the global state JSON, checks conditions,
  executes changes, saves the state, adds any additional
  details for the client to know, then notifies the client.

  Most functions begin with the setting of local "shortcut"
  variables such as $game for $this->game or $cards for $this->cards
  just to make the code easier to read and possibly to give
  a slight performance improvement.
  */

class GameOn extends GameState
{
    public array $st;

    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: 31,
            type: StateType::ACTIVE_PLAYER,
            descriptionMyTurn: clienttranslate('...')
        );
    }

    function onEnteringState() {}

    #[PossibleAction]
    public function actPass(int $currentPlayerId)
    {
        $game = $this->game;
        $cards = $game->cards;

        // $game->notify->all('Debug', 'actPass(' . ", {$currentPlayerId})");

        $st = json_decode($this->bga->globals->get('state'), true);

        $demand = $st['demand'];
        if ($st["skip{$currentPlayerId}"] > 0) {
            // no changes to demands, which go to the next
            // non-skipped player
            $st["skip{$currentPlayerId}"]--;
        } else  if ($demand == Game::SKIP_DEMAND) {
            $st["skip{$currentPlayerId}"] += $st['demandArg'] - 1;
            $st['demand'] = Game::NO_DEMAND;
            $st['demandArg'] = 0;
        } else if ($demand == Game::DRAW_DEMAND) {
            return $game->err($currentPlayerId, 'You cannot pass when you have to draw.');
        } else if ($demand == Game::SUIT_DEMAND) {
            $st['demand'] = Game::NO_DEMAND;
            $st['demandArg'] = 0;
        } else if (
            $demand == Game::NO_DEMAND
            && $st['drew'] == 0
        )
            return $game->err($currentPlayerId, 'You have to draw before passing.');

        // all good, go ahead
        if (
            $demand == Game::RANK_DEMAND
            && $st['lastJack'] == $currentPlayerId
        ) {
            // remove rank demand if I made it
            $st['demand'] = Game::NO_DEMAND;
            $st['demandArg'] = 0;
            $st['lastJack'] = 0;
        }

        $st['drew'] = 0;

        $this->bga->globals->set('state', json_encode($st));

        // vvvvvv parameters not saved but sent to the client
        $st['playerId'] = $currentPlayerId;
        $st['playerName'] = $game->getPlayerNameById($currentPlayerId);

        $game->notify->all(
            'Pass',
            clienttranslate('${playerName} passes.'),
            $st,
        );

        if ($st['reverse'] > 0) return PrevPlayer::class;
        else return NextPlayer::class;
    }

    #[PossibleAction]
    public function actDraw(int $currentPlayerId)
    {
        $game = $this->game;
        $cards = $game->cards;

        // $game->notify->all('Debug', 'actDraw(' . ", {$currentPlayerId})");

        $st = json_decode($this->bga->globals->get('state'), true);

        $demand = $st['demand'];

        if ($st["skip{$currentPlayerId}"] > 0) return $game->err($currentPlayerId, 'You have to skip.');

        if (
            $demand != Game::NO_DEMAND
            && $demand != Game::DRAW_DEMAND
        )
            return $game->err($currentPlayerId, 'You have to meet the demand or pass.');

        if ($st['drew'] > 0) return $game->err($currentPlayerId, 'You can only draw once.');

        if ($demand == Game::DRAW_DEMAND) $draw = $st['demandArg'];
        else $draw = 1;

        $st['reshuffled'] = false;
        $deckCount = $cards->countItemsInLocation('deck');
        $discards = $cards->getItemsInLocation('discard')->values();
        $discardCount = count($discards);
        if ($draw >= $deckCount) {
            $st['reshuffled'] = true;
            for ($i = 0; $i < $discardCount - 1; $i++) {
                $cards->moveItem($discards[$i], 'deck');
                $cards->shuffle('deck');
            }

            // reset jokers in the deck
            foreach ($cards->getItemsInLocation('deck')->values() as $card) {
                if ($card->joker == 1) {
                    $card->rank = 14;
                    $card->suit = 0;
                    $cards->updateItem($card, ['rank', 'suit']);
                }
            }
            $st['discard'] = $cards->getItemsInLocation('discard')->values();
        }

        $drawnCards = $cards->pickItems($draw, 'deck', ['hand', $currentPlayerId])->values();

        $st['deck'] = $cards->countItemsInLocation('deck');

        if ($demand == Game::DRAW_DEMAND) {
            $st['demand'] = Game::NO_DEMAND;
            $st['demandArg'] = 0;
            $st['reverse'] = 0;
            $st['battleKing'] = 0;
            $st['drew'] = 0;
        } else
            $st['drew'] = $draw;

        $this->bga->globals->set('state', json_encode($st));

        // vvvvvv parameters not saved but sent to the client
        $st['playerId'] = $currentPlayerId;
        $st['playerName'] = $game->getPlayerNameById($currentPlayerId);
        $st['playerDrew'] = $draw;
        $st["hand{$currentPlayerId}"] = $cards->countItemsInLocation(['hand', $currentPlayerId]);
        $st['_private'] = [
            $currentPlayerId => [
                'cards' => $drawnCards
            ]
        ];

        $message = '${playerName} draws a card from the deck.';
        if ($draw > 1) $message = '${playerName} draws ${playerDrew} cards from the deck.';
        $game->notify->all(
            'DrawCards',
            clienttranslate($message),
            $st,
        );

        if ($demand == Game::DRAW_DEMAND) return NextPlayer::class;
    }

    #[PossibleAction]
    public function actPlay(#[IntArrayParam()] array $cardIds, #[JsonParam(associative: true)] array $jokers, int $currentPlayerId, int $rankDemand = 0, int $suitDemand = 0)
    {
        $globals = $this->bga->globals;
        $game = $this->game;
        $cards = $game->cards;

        // $game->notify->all('Debug', 'actPlay(' . json_encode($cardIds) . ', ' . json_encode($jokers) . ", {$currentPlayerId}, {$rankDemand}, {$suitDemand})");
        $st = json_decode($globals->get('state'), true);

        if ($st["skip{$currentPlayerId}"] > 0) return $game->err($currentPlayerId, 'You must pass.');
        if (count($cardIds) < 1) return $game->err($currentPlayerId, 'You have no cards to play.');

        $playedCards = array_map(fn($cardId) => $cards->getItemById($cardId), $cardIds);

        $playedCount = count($cardIds);

        $demand = $st['demand'];
        $demandArg = $st['demandArg'];
        $battleKing = $st['battleKing'];
        $lastJack = $st['lastJack'];
        $reverse = $st['reverse'];
        $rankPick = 0;
        $suitPick = 0;

        $discards = $cards->getItemsInLocation('discard')->values();
        $top = $discards[count($discards) - 1];

        for ($i = 0; $i < count($playedCards); $i++) {
            $card = $playedCards[$i];

            if (array_key_exists($card->id, $jokers)) {
                $card->rank = $jokers[$card->id]['rank'];
                $card->suit = $jokers[$card->id]['suit'];
                $cards->updateItem($card, ['rank', 'suit']);
            }

            switch ($demand) {
                case Game::NO_DEMAND:
                    switch ($card->rank) {
                        case Game::ACE:
                            if ($i > 0) return $game->err($currentPlayerId, 'You can only play a demand or non-action cards.');
                            $demand = Game::SUIT_DEMAND;
                            if ($suitDemand > 0) $demandArg = $suitDemand;
                            else $suitPick = $currentPlayerId;
                            break;
                        case 2:
                            if ($i > 0) return $game->err($currentPlayerId, 'You can only play a demand or non-action cards.');
                            $demand = Game::DRAW_DEMAND;
                            $demandArg += 2;
                            break;
                        case 3:
                            if ($i > 0) return $game->err($currentPlayerId, 'You can only play a demand or non-action cards.');
                            $demand = Game::DRAW_DEMAND;
                            $demandArg += 3;
                            break;
                        case 4:
                            if ($i > 0) return $game->err($currentPlayerId, 'You can only play a demand or non-action cards.');
                            $demand = Game::SKIP_DEMAND;
                            $demandArg++;
                            break;
                        case Game::JACK:
                            if ($i > 0) return $game->err($currentPlayerId, 'You can only play a demand or non-action cards.');
                            $demand = Game::RANK_DEMAND;
                            $lastJack = $currentPlayerId;
                            if ($rankDemand > 0) $demandArg = $rankDemand;
                            else $rankPick = $currentPlayerId;
                            break;
                        case Game::KING:
                            switch ($card->suit) {
                                case Game::SPADE:
                                    $reverse = $currentPlayerId;
                                case Game::HEART:
                                    if ($i > 0) return $game->err($currentPlayerId, 'You can only play a demand or non-action cards.');
                                    $demand = Game::DRAW_DEMAND;
                                    $demandArg += 5;
                                    $battleKing = $currentPlayerId;
                                    break;
                                case Game::DIAMOND:
                                case Game::CLUB:
                                    if ($battleKing) {
                                        $demand = Game::NO_DEMAND;
                                        $demandArg = 0;
                                        $battleKing = 0;
                                        $reverse = 0;
                                    }
                                    break;
                            }
                            break;
                        default:
                            if ($game->tableOptions->get(103) == 0 && $i == 0)
                                return $game->err($currentPlayerId, 'Sequences not allowed per table option.');
                            if (
                                $top->rank != Game::QUEEN
                                && $card->rank != Game::QUEEN
                                && $card->suit != $top->suit
                                && abs($card->rank - $top->rank) > 1
                            ) return $game->err($currentPlayerId, 'Cards must be in sequence or the same rank.');
                            $top = $card;
                    }
                    break;
                case Game::DRAW_DEMAND:
                    switch ($card->rank) {
                        case 2:
                            $demandArg += 2;
                            break;
                        case 3:
                            $demandArg += 3;
                            break;
                        case Game::KING:
                            switch ($card->suit) {
                                case Game::SPADE:
                                    $reverse = $currentPlayerId;
                                    // and fall through
                                case Game::HEART:
                                    $demandArg += 5;
                                    $battleKing = $currentPlayerId;
                                    break;
                                case Game::DIAMOND:
                                case Game::CLUB:
                                    // clear battle king
                                    $battleKing = 0;
                                    $demandArg = 0;
                                    $demand = Game::NO_DEMAND;
                                    $reverse = 0;
                                    break;
                            }
                            break;
                        default:
                            return $game->err($currentPlayerId, "You can only play draw cards.");
                    }
                    break;
                case Game::SKIP_DEMAND:
                    if (
                        $card->rank != 4
                    ) return $game->err($currentPlayerId, "You can only play skip cards.");
                    $demandArg++;
                    break;
                case Game::RANK_DEMAND:
                    if ($card->rank == Game::JACK) {
                        if ($i > 0) return $game->err($currentPlayerId, 'You can only play one card.');
                        if ($rankDemand) $demandArg = $rankDemand;
                        else $rankPick = $currentPlayerId;
                        $lastJack = $currentPlayerId;
                    } else if (
                        $card->rank != $demandArg
                        && $demandArg != Game::ANY_RANK
                        || $card->rank < 5
                        || $card->rank > 10
                    )
                        return $game->err($currentPlayerId, "You need to play the rank.");
                    else if ($currentPlayerId == $lastJack) {
                        $lastJack = 0;
                        $demand = 0;
                        $demandArg = 0;
                    }
                    break;
                case Game::SUIT_DEMAND:
                    if ($playedCount > 1)
                        return $game->err($currentPlayerId, "You can only play one card.");
                    if (
                        $demandArg < 20 && $playedCards[0]->suit != $demandArg
                        || $playedCards[0]->rank < 5
                        || $playedCards[0]->rank > 10
                    ) return $game->err($currentPlayerId, "You need to play the suit.");

                    // we've cleared demand
                    $demand = Game::NO_DEMAND;
                    $demandArg = 0;
                    break;
            }
            $top = $card;
        }

        //////////// valid play, enact
        $st['demand'] = $demand;
        $st['demandArg'] = $demandArg;
        $st['battleKing'] = $battleKing;
        $st['lastJack'] = $lastJack;
        $st['reverse'] = $reverse;
        $st['rankPick'] = $rankPick;
        $st['suitPick'] = $suitPick;

        $st['drew'] = 0;

        // move the cards to the discard pile
        // one at a time to maintain order
        foreach ($playedCards as $card) {
            $cards->moveItem($card, 'discard');
        }

        $globals->set('state', json_encode($st));

        $message = '${playerName} plays the ';
        for ($i = 0; $i < count($playedCards); $i++) {
            $message = $message . $this->getCardName($playedCards[$i]);
            if ($i < count($playedCards)) $message = $message . ', ';
        }
        switch ($demand) {
            case Game::DRAW_DEMAND:
                $direction = 'next';
                if ($st['reverse']) $direction = 'previous';
                $message = $message . " and the {$direction} player draws {$demandArg} cards";
                break;
            case Game::SKIP_DEMAND:
                $message = $message . " and the next player is skipped {$demandArg} times";
                break;
            case Game::RANK_DEMAND:
                if ($demandArg and $st['lastJack'] == $currentPlayerId)
                    $message = $message . ' and demands everyone play ' . $game->cardTypes['rank'][$demandArg]['name'];
                break;
            case Game::SUIT_DEMAND:
                if ($demandArg)
                    $message = $message . ' and demands the next player play ' . $game->cardTypes['suit'][$demandArg]['name'];
                break;
        }
        $message = $message . '.';

        // vvvvvv parameters not saved but sent to the client
        $st['playerName'] = $game->getPlayerNameById($currentPlayerId);
        $st["hand{$currentPlayerId}"] = $cards->countItemsInLocation(['hand', $currentPlayerId]);
        $st['playerId'] = $currentPlayerId;
        $st['cards'] = $playedCards;
        $st['discard'] = $cards->getItemsInLocation('discard')->values();

        $game->notify->all('PlayCards', clienttranslate($message), $st);

        if ($rankPick == 0 && $suitPick == 0)
            return NextPlayer::class;
    }

    #[PossibleAction]
    public function actPickRank(int $rank, int $currentPlayerId)
    {
        $game = $this->game;

        $st = json_decode($this->bga->globals->get('state'), true);

        if ($st['rankPick'] != $currentPlayerId) return null;

        $st['rankPick'] = 0;
        $st['demand'] = GAME::RANK_DEMAND;
        $st['demandArg'] = $rank;
        $st['lastJack'] = $currentPlayerId;

        $this->bga->globals->set('state', json_encode($st));

        // vvvvvv parameters not saved but sent to the client
        $st['playerName'] = $game->getPlayerNameById($currentPlayerId);
        $st['rankDemandName'] = $game->cardTypes['rank'][$rank]['name'];

        $this->game->bga->notify->all('Selected', clienttranslate('${playerName} demands everyone play ${rankDemandName}.'), $st);

        if ($st['reverse'] > 0) return PrevPlayer::class;
        else return NextPlayer::class;
    }

    #[PossibleAction]
    public function actPickSuit(int $suit, int $currentPlayerId)
    {
        $game = $this->game;

        $st = json_decode($this->bga->globals->get('state'), true);

        if ($st['suitPick'] != $currentPlayerId) return null;

        $st['suitPick'] = 0;
        $st['demand'] = GAME::SUIT_DEMAND;
        $st['demandArg'] = $suit;

        $this->bga->globals->set('state', json_encode($st));

        // vvvvvv parameters not saved but sent to the client
        $st['playerName'] = $game->getPlayerNameById($currentPlayerId);
        $st['suitName'] = $game->cardTypes['suit'][$st['demandArg']]['name'] . ' suit';

        $this->game->bga->notify->all('Selected', clienttranslate('${playerName} demands the next player play a non-action card of ${suitName}.'), $st);

        if ($st['reverse'] > 0) return PrevPlayer::class;
        else return NextPlayer::class;
    }


    #[CheckAction(false)]
    public function actMakau(int $onPlayerId, int $currentPlayerId)
    {
        $game = $this->game;
        $globals = $this->bga->globals;
        $cards = $game->cards;

        $st = json_decode($this->bga->globals->get('state'), true);

        if ($st["makau{$onPlayerId}"] > 0 || $cards->countItemsInLocation(['hand', $onPlayerId]) != 1) {
            return;
        }

        $st["makau{$onPlayerId}"] = $currentPlayerId;

        if ($currentPlayerId != $onPlayerId)
            $drawnCards = $cards->pickItems(5, 'deck', ['hand', $onPlayerId])->values();

        $globals->set('state', json_encode($st));

        // vvvvvv parameters not saved but sent to the client
        $st["hand{$onPlayerId}"] = $cards->countItemsInLocation(['hand', $onPlayerId]);
        $st['onPlayerId'] = $onPlayerId;
        $st['playerName'] = $this->game->getPlayerNameById($currentPlayerId);
        $st['deck'] = $cards->countItemsInLocation('deck');

        $message = '${playerName} called Makau!';

        if ($currentPlayerId != $onPlayerId) {
            $st['_private'] = [
                $onPlayerId => [
                    'cards' => $drawnCards
                ]
            ];

            $st['onPlayerName'] = $this->game->getPlayerNameById($onPlayerId);
            $message = '${playerName} called Makau on ${onPlayerName}, who draws 5!';
        }

        $game->bga->notify->all('CalledMakau', clienttranslate($message), $st);

        return GameOn::class;
    }

    public function zombie(int $playerId)
    {
        $globals = $this->bga->globals;
        $game = $this->game;
        $cards = $game->cards;
        $discards = $cards->getItemsInLocation('discard')->values();
        $top = $discards[count($discards) - 1];

        $handCards = $cards->getItemsInLocation(['hand', $playerId])->values();

        $st = json_decode($this->bga->globals->get('state'), true);

        if ($st["skip{$playerId}"] > 0) return $this->actPass($playerId);

        // compute which cards can be played
        $actionCards = [];
        $nonActionCards = [];
        $jokers = [];
        $rankDemand = 0;
        $suitDemand = 0;

        switch ($st['demand']) {
            case Game::NO_DEMAND:
                foreach ($handCards as $card) {
                    switch ($card->rank) {
                        case 2:
                        case 3:
                        case 4:
                        case Game::JACK:
                        case Game::KING:
                            if (
                                $card->suit == $top->suit
                                || $card->rank == $top->rank
                            )
                                $actionCards[] = $card;
                            break;
                        // wilds
                        case Game::ACE:
                        case Game::JOKER:
                            $actionCards[] = $card;
                            break;
                        default:
                            if (
                                $card->rank == $top->rank
                                || $card->suit == $top->suit
                                || $top->rank == Game::QUEEN
                            )
                                $nonActionCards[] = $card;
                    }
                }

                if (count($actionCards) > 0) {
                    $card = $actionCards[rand(0, count($actionCards) - 1)];

                    switch ($card->rank) {
                        case Game::JOKER:
                            $jokers[$card->id] = [
                                'rank' => 4,
                                'suit' => $top->suit,
                            ];
                            break;
                        case Game::JACK:
                            $rankDemand = 20; // in case we don't find any
                            foreach ($handCards as $handCard) {
                                if ($handCard->rank > 4 && $handCard->rank < 11) {
                                    $rankDemand = $handCard->rank;
                                    break;
                                }
                            }
                            break;
                        case Game::ACE:
                            $suitDemand = 20; // in case we don't find any
                            foreach ($handCards as $handCard) {
                                if ($handCard->rank > 4 && $handCard->rank < 11) {
                                    $suitDemand = $handCard->suit;
                                    break;
                                }
                            }
                            break;
                    }

                    return $this->actPlay([$card->id], $jokers, $playerId, $rankDemand, $suitDemand);
                } else if (count($nonActionCards) > 0) {
                    // TODO: handle sequences, possibly more valuable than action cards!
                    $card = $nonActionCards[rand(0, count($nonActionCards) - 1)];
                    return $this->actPlay([$card->id], $jokers, $playerId);
                } else {
                    if ($st['drew'] > 0) return $this->actPass($playerId);
                    $this->actDraw($playerId);
                    return $this->zombie($playerId);
                }
            case Game::DRAW_DEMAND:
                foreach ($handCards as $card) {
                    if (
                        $card->rank == 2
                        || $card->rank == 3
                        || $card->rank == Game::KING
                        && (
                            $card->suit < 3
                            || $st['battleKing'] > 0
                        )
                        || $card->rank == Game::JOKER // should I use a joker for a 5-card draw?
                    ) $actionCards[] = $card;
                }

                if (count($actionCards) > 0) {
                    $card = $actionCards[rand(0, count($actionCards) - 1)];
                    if ($card->joker > 0) {
                        $jokers[$card->id] = [
                            'rank' => Game::KING,
                            'suit' => Game::HEART,
                        ];
                    }

                    // play all the draw cards
                    return $this->actPlay([$card->id], $jokers, $playerId);
                } else {
                    return $this->actDraw($playerId);
                }
            case Game::SKIP_DEMAND:
                foreach ($handCards as $card) {
                    if (
                        $card->rank == 4
                        || $card->rank == Game::JOKER // should I use a joker for a skip?
                    ) $actionCards[] = $card;
                }

                if (count($actionCards) > 0) {
                    $card = $actionCards[rand(0, count($actionCards) - 1)];

                    if ($card->joker > 0) {
                        $jokers[$card->id] = [
                            'rank' => 4,
                            'suit' => Game::CLUB,
                        ];
                    }

                    // play all the skip cards
                    return $this->actPlay([$card->id], $jokers, $playerId);
                } else {
                    return $this->actPass($playerId);
                }
            case Game::RANK_DEMAND:
                foreach ($handCards as $card) {
                    if ($card->rank == Game::JOKER) {
                        $card->rank = Game::JACK;
                        $card->suit = Game::HEART;
                        $actionCards[] = $card;
                    } else if (
                        $card->rank == $st['demandArg']
                        || (
                            $card->rank > 4
                            && $card->rank < 11
                            && $st['demandArg'] == Game::ANY_RANK
                        )
                    ) $nonActionCards[] = $card;
                }

                if (count($nonActionCards) > 0) {
                    // best to use non-action to meet demand
                    $card = $nonActionCards[rand(0, count($nonActionCards) - 1)];

                    return $this->actPlay([$card->id], $jokers, $playerId, $rankDemand);
                } else if (count($actionCards) > 0) {
                    $card = $actionCards[rand(0, count($actionCards) - 1)];

                    if ($card->rank == Game::JACK) {
                        $rankDemand = 20; // in case we don't find any
                        foreach ($handCards as $handCard) {
                            if ($handCard->rank > 4 && $handCard->rank < 11) {
                                $rankDemand = $handCard->rank;
                                break;
                            }
                        }
                    }

                    if ($card->joker) {
                        $rank = 10;
                        if ($st['demandArg'] < 20) $rank = $st['demandArg'];
                        $jokers[$card->id] = [
                            'rank' => $rank,
                            'suit' => Game::CLUB,
                        ];
                    }

                    return $this->actPlay([$card->id], $jokers, $playerId);
                } else {
                    return $this->actPass($playerId);
                }
            case Game::SUIT_DEMAND:
                foreach ($handCards as $card) {
                    if ($card->joker) $actionCards[] = $card;
                    else if (
                        (
                            $card->suit == $st['demandArg']
                            || $st['demandArg'] == Game::ANY_SUIT
                        )
                        && $card->rank > 4 && $card->rank < 11
                    ) $nonActionCards[] = $card;
                }

                if (count($nonActionCards) > 0) {
                    // best to use non-action to meet demand
                    $card = $nonActionCards[rand(0, count($nonActionCards) - 1)];
                    return $this->actPlay([$card->id], $jokers, $playerId);
                } else if (count($actionCards) > 0) {
                    $card = $actionCards[rand(0, count($actionCards) - 1)];
                    if ($card->joker) {
                        $jokers[$card->id] = [
                            'rank' => 10,
                            'suit' => $st['demandArg'],
                        ];
                    }
                    return $this->actPlay([$card->id], $jokers, $playerId);
                } else {
                    return $this->actPass($playerId);
                }
        }
    }

    function getDrawCards($cards): array
    {
        $matchingCards = [];

        for ($i = 0; $i < count($cards); $i++) {
            switch ($cards[$i]->rank) {
                case 2:
                case 3:
                case Game::KING:
                    $matchingCards[] = $cards[$i];
                    break;
            }
        }

        return $matchingCards;
    }

    function getSkipCards($cards): array
    {
        $matchingCards = [];

        for ($i = 0; $i < count($cards); $i++) {
            if ($cards[$i]->rank == 4)
                $matchingCards[] = $cards[$i];
        }

        return $matchingCards;
    }

    function getCardName($card): string
    {
        if ($card->rank > 13) return "Joker (id={$card->id} rank={$card->rank} suit={$card->suit})";
        return ($this->game->cardTypes['rank'][$card->rank]['name'] . " of " . $this->game->cardTypes['suit'][$card->suit]['name'] . 's');
    }

    function getCardNames($cards): string
    {
        return implode(", ", array_map(fn($card) => $this->getCardName($card), $cards));
    }

    /*    function debug_playToEndRound()
    {
        // actually only does one move, whatev!
        foreach ($this->gamestate->getActivePlayerList() as $playerId) {
            $playerId = (int)$playerId;
            $this->gamestate->runStateClassZombie($this->gamestate->getCurrentState($playerId), $playerId);
        }
    }*/
}
