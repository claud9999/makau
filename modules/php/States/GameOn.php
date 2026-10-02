<?php

declare(strict_types=1);

namespace Bga\Games\makaucloudnein\States;

use Bga\Games\makaucloudnein\Game;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\UserException;
use Bga\GameFramework\Actions\Types\IntArrayParam;
use Bga\GameFramework\NotificationMessage;

class GameOn extends GameState
{
    public array $st;

    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: 31,
            type: StateType::MULTIPLE_ACTIVE_PLAYER,
            descriptionMyTurn: clienttranslate('${you} may play cards or draw')
        );
    }

    function onEnteringState()
    {
        $this->gamestate->setAllPlayersMultiactive();
    }

    #[PossibleAction]
    public function actPass(int $currentPlayerId)
    {
        $game = $this->game;
        $st = json_decode($this->bga->globals->get('state'), true);

        if ($currentPlayerId != $st['fwPlayerId'])
            return $game->err($currentPlayerId, 'not your turn');

        $skip = $st["skip{$currentPlayerId}"] + $st['skip'];

        // I have more skips to skip
        if ($skip > 0) {
            $st["skip{$currentPlayerId}"] = $skip - 1;
            $st['skip'] = 0;
        }

        // carry draws forward
        if ($st['draw'] > 0) {
            $st["draw{$currentPlayerId}"] += $st['draw'];
            $st['draw'] = 0;
        }

        $st['suitDemand'] = 0;

        $st['fwPlayerId'] = $this->nextPlayerId($currentPlayerId);
        $st['drew'] = 0;

        if ($st['lastJack'] == $currentPlayerId) {
            $st['lastJack'] = 0;
            $st['rankDemand'] = 0;
        }

        $this->bga->globals->set('state', json_encode($st));

        $st['playerId'] = $currentPlayerId;
        $st['playerName'] = $game->getPlayerNameById($currentPlayerId);

        $game->notify->all(
            'Pass',
            clienttranslate('${playerName} passes'),
            $st,
        );
    }

    #[PossibleAction]
    public function actDraw(int $currentPlayerId)
    {
        $game = $this->game;
        $cards = $game->cards;
        $st = json_decode($this->bga->globals->get('state'), true);

        if ($currentPlayerId != $st['fwPlayerId']) return $game->err($currentPlayerId, 'not your turn');

        if ($st['skip'] + $st["skip{$currentPlayerId}"] > 0) return $game->err($currentPlayerId, 'have to skip');
        if ($st['suitDemand'] > 0) return $game->err($currentPlayerId, 'have to match suit');

        if ($st['drew'] > 0) return $game->err($currentPlayerId, 'can only draw once');
        $forcedDraw = false;
        $draw = $st['draw'] + $st["draw{$currentPlayerId}"];
        if ($st['bwPlayerId'] == $currentPlayerId) $draw += $st['bwDraw'];
        if ($draw == 0) $draw = 1;
        else $forcedDraw = true;

        $drawnCards = $cards->pickItems($draw, 'deck', ['hand', $currentPlayerId])->values();

        $st['drew'] = $draw;
        $st['deck'] = $cards->countItemsInLocation('deck');
        $st["hand{$currentPlayerId}"] = $cards->countItemsInLocation(['hand', $currentPlayerId]);

        if ($forcedDraw) {
            $st['draw'] = 0;
            $st["draw{$currentPlayerId}"] = 0;
            if ($st['bwPlayerId'] == $currentPlayerId) {
                $st['bwPlayerId'] = 0;
                $st['bwDraw'] = 0;
            }
            $st['battleKing'] = 0;
            $st['drew'] = 0;

            $st['fwPlayerId'] = $this->nextPlayerId($currentPlayerId);
        }

        $this->bga->globals->set('state', json_encode($st));

        $st['playerId'] = $currentPlayerId;
        $st['playerName'] = $game->getPlayerNameById($currentPlayerId);
        $st['_private'] = [
            $currentPlayerId => [
                'cards' => $drawnCards
            ]
        ];

        $game->notify->all(
            'DrawCards',
            clienttranslate('${playerName} takes card(s) from the deck'),
            $st,
        );
    }

    #[PossibleAction]
    public function actPlay(#[IntArrayParam()] array $cardIds, int $currentPlayerId)
    {
        $game = $this->game;
        $cards = $game->cards;
        $st = json_decode($this->bga->globals->get('state'), true);

        if (
            $currentPlayerId != $st['bwPlayerId']
            && $currentPlayerId != $st['fwPlayerId']
        ) return $game->err($currentPlayerId, 'not your turn');

        if ($st["draw{$currentPlayerId}"] > 0) return $game->err($currentPlayerId, 'you must draw');
        if ($st["skip{$currentPlayerId}"] > 0) return $game->err($currentPlayerId, 'you must pass');
        if (count($cardIds) < 1) return $game->err($currentPlayerId, 'no cards to play');

        $playedCards = [];

        for ($i = 0; $i < count($cardIds); $i++) {
            $playedCards[] = $cards->getItemById($cardIds[$i]);
        }

        if ($st['skip'] > 0) {
            //////////////// pending skips            
            $playableCards = $this->getSkipCards($playedCards);
            if (count($playableCards) != count($playedCards))
                return $game->err($currentPlayerId, 'you can only add skip cards');

            for ($i = 0; $i < count($playableCards); $i++) {
                $st['skip']++;
            }
        } else if (
            $st['fwPlayerId'] == $currentPlayerId && $st['draw'] > 0
            || $st['bwPlayerId'] == $currentPlayerId
        ) {
            //////////////// pending draw
            $playableCards = $this->getDrawCards($playedCards);
            if (count($playableCards) != count($playedCards))
                return $game->err($currentPlayerId, 'you can only add draw cards');

            for ($i = 0; $i < count($playableCards); $i++) {
                switch ($playableCards[$i]->rank) {
                    case 2:
                        $this->addToDraw($currentPlayerId, $st, 2);
                        break;
                    case 3:
                        $this->addToDraw($currentPlayerId, $st, 3);
                        break;
                    case 13:
                        switch ($playableCards[$i]->suit) {
                            case 1: // KS
                            case 2: // KD
                                $this->addToDraw($currentPlayerId, $st, 5);
                                break;
                            case 3: // KH
                            case 4: // KC
                                if ($st['fwPlayerId'] == $currentPlayerId) $st['draw'] = 0;
                                if ($st['bwPlayerId'] == $currentPlayerId) {
                                    $st['bwDraw'] = 0;
                                    $st['bwPlayerId'] = 0;
                                }
                                break;
                        }
                        break;
                }
            }
        } else if ($st['rankDemand']) {
            //////////////// rank demand
            $playableCards = [];
            for ($i = 0; $i < count($playedCards); $i++) {
                $card = $playedCards[$i];
                if (
                    $card->rank == $st['rankDemand']
                    || $card->rank == 11
                )
                    $playableCards[] = $card;
            }
        } else if ($st['suitDemand']) {
            //////////////// suit demand
            $playableCards = [];
            for ($i = 0; $i < count($playedCards); $i++) {
                $card = $playedCards[$i];
                if (
                    $card->rank > 4
                    && $card->rank < 11
                    && $card->suit == $st['suitDemand']
                )
                    $playableCards[] = $card;
            }
        } else {
            //////////////// no pending demands
            $demand = '';

            //////////////// check that there are, at most, one demand type
            for ($i = 0; $i < count($playedCards); $i++) {
                $card = $playedCards[$i];

                $newdemand = $demand;

                switch ($card->rank) {
                    case 2:
                    case 3:
                        $newdemand = 'draw';
                        break;
                    case 4:
                        $newdemand = 'skip';
                        break;
                    case 11: // J
                        $newdemand = 'rank';
                        break;
                    case 13: // K
                        if ($card->suit < 3) $newdemand = 'draw';
                        break;
                    case 14: // A
                        $newdemand = 'suit';
                        break;
                }

                if ($demand != '' && $newdemand != $demand)
                    return $game->err($currentPlayerId, 'You cannot play multiple demands');

                $demand = $newdemand;
            }

            $top = $cards->getItemOnTop('discard');

            //////////////// check that the cards are actually playable
            for ($i = 0; $i < count($playedCards); $i++) {
                $card = $playedCards[$i];

                if ($demand != '') {
                    switch ($card->rank) {
                        case 2:
                            if ($demand != 'draw') return $game->err($currentPlayerId, 'You cannot play a two when another demand is in play');
                            break;
                        case 3:
                            if ($demand != 'draw') return $game->err($currentPlayerId, 'You cannot play a three when another demand is in play');
                            break;
                        case 4:
                            if ($demand != 'skip') return $game->err($currentPlayerId, 'You cannot play a four when another demand is in play');
                            break;
                        case 11: // J
                            if ($demand != 'rank') return $game->err($currentPlayerId, 'You cannot play a jack when another demand is in play');
                            break;
                        case 13: // K
                            if ($demand != 'draw') return $game->err($currentPlayerId, 'You cannot play a king when another demand is in play');
                            break;
                        case 14: // A
                            if ($demand != 'suit') return $game->err($currentPlayerId, 'You cannot play an ace when another demand is in play');
                            break;
                    }
                }

                if (
                    $demand == 'draw' && ($card->rank < 4 || $card->rank == 13)
                    || $demand = 'skip' && $card->rank == 4
                    || $card->rank == 14 // A = wild
                    || $card->rank == 12 // Q = wild
                    || $card->suit == $top->suit
                    || $card->rank == $top->rank
                    || $top->rank == 12 // wild = Q
                ) {
                    $playableCards[] = $card;
                    $top = $card;
                } else {
                    return $game->err(
                        $currentPlayerId,
                        'You cannot play ' . $this->getCardName($card) . ' top=' . $this->getCardName($top)
                    );
                }
            }
        }

        //////////// valid play, enact

        $st['fwPlayerId'] = $this->nextPlayerId($currentPlayerId);

        for ($i = 0; $i < count($playedCards); $i++) {
            $card = $playedCards[$i];
            switch ($card->rank) {
                case 2:
                    $st['draw'] += 2;
                    break;
                case 3:
                    $st['draw'] += 3;
                    break;
                case 4:
                    $st['skip']++;
                    break;
                case 11:
                    // J demands rank
                    $st['rankPick'] = $currentPlayerId;
                    $st['lastJack'] = $currentPlayerId;
                    $st['fwPlayerId'] = $currentPlayerId;
                    break;
                case 13:
                    switch ($card->suit) {
                        case 1: // KS back draw 5
                            // new backward draw
                            $st['bwPlayerId'] = $this->prevPlayerId($currentPlayerId);
                            $st['battleKing'] = $currentPlayerId;
                            $st['bwDraw'] = 5;
                            break;
                        case 2: // KH draw 5
                            $st['battleKing'] = $currentPlayerId;
                            $st['draw'] = 5;
                            break;
                        case 3:
                        case 4:
                            // KD KC blocks battle kings
                            if ($st['battleKing'] > 0) {
                                if ($st['fwPlayerId'] == $currentPlayerId)
                                    $st['draw'] = 0;
                                if ($st['bwPlayerId'] == $currentPlayerId) {
                                    $st['bwDraw'] = 0;
                                    $st['bwPlayerId'] = 0;
                                }
                            }
                            break;
                    }
                    break;
                case 14:
                    // A demands suit
                    $st['suitPick'] = $currentPlayerId;
                    $st['fwPlayerId'] = $currentPlayerId;
                    break;
            }
        }
        // move the cards to the discard pile
        // one at a time to maintain order
        for ($i = 0; $i < count($playedCards); $i++) {
            $cards->moveItem($playedCards[$i], 'discard');
        }

        $st["hand{$currentPlayerId}"] = $cards->countItemsInLocation(['hand', $currentPlayerId]);

        if ($st['suitDemand']) {
            $st['suitDemand'] = 0;
        }

        if ($st['battleKing'] != 0 && $st['battleKing'] != $currentPlayerId)
            $st['battleKing'] = 0;

        $st['drew'] = 0;

        if ($st['lastJack'] == $currentPlayerId) {
            $st['lastJack'] = 0;
            $st['rankDemand'] = 0;
        }

        $this->bga->globals->set('state', json_encode($st));

        $st['playerId'] = $currentPlayerId;
        $st['cards'] = $playedCards;

        $game->notify->all('PlayCards', '', $st);
    }

    #[PossibleAction]
    public function actPickRank(int $rank, int $currentPlayerId)
    {
        $st = json_decode($this->bga->globals->get('state'), true);

        if ($st['rankPick'] != $currentPlayerId) return null;

        $fwPlayerId = $this->nextPlayerId($currentPlayerId);

        $st['rankDemand'] = $rank;
        $st['rankPick'] = 0;
        $st['lastJack'] = $currentPlayerId;
        if ($st['suitPick'] == 0) $st['fwPlayerId'] = $fwPlayerId;

        $this->bga->globals->set('state', json_encode($st));

        $this->game->bga->notify->all('Selected', 'Rank selected', $st);
    }

    #[PossibleAction]
    public function actPickSuit(int $suit, int $currentPlayerId)
    {
        $st = json_decode($this->bga->globals->get('state'), true);

        if ($st['suitPick'] != $currentPlayerId) return null;

        $fwPlayerId = $this->nextPlayerId($currentPlayerId);

        $st['suitDemand'] = $suit;
        $st['suitPick'] = 0;
        if ($st['rankPick'] == 0) $st['fwPlayerId'] = $fwPlayerId;

        $this->bga->globals->set('state', json_encode($st));

        $this->game->bga->notify->all('Selected', 'Suit selected', $st);
    }


    #[PossibleAction]
    public function actMakau(int $currentPlayerId, int $onPlayerId)
    {
        $globals = $this->bga->globals;
        $cards = $this->game->cards;

        $st = json_decode($this->bga->globals->get('state'), true);

        if ($st["makau{$onPlayerId}"] > 0) return;

        $st["makau{$onPlayerId}"] = $onPlayerId;

        if ($currentPlayerId != $onPlayerId) {
            $drawnCards = $cards->pickItems(5, 'deck', ['hand', $onPlayerId])->values();
            $st['deck'] = $cards->countItemsInLocation('deck');
            $st["hand{$onPlayerId}"] = $cards->countItemsInLocation(['hand', $onPlayerId]);
        } else {
            $drawnCards = [];
        }

        $globals->set('state', json_encode($st));

        $st['onPlayerId'] = $onPlayerId;
        if (count($drawnCards) > 0)
            $st['_private'] = [
                $onPlayerId => [
                    'cards' => $drawnCards
                ]
            ];

        $this->game->bga->notify->all('CalledMakau', 'called makau', $st);
    }

    public function zombie(int $playerId)
    {
        // We must implement this so BGA can auto play in the case a player becomes a zombie, but for this tutorial we won't handle this case
        throw new UserException("Not implemented: zombie for player {$playerId}");
    }

    function getDrawCards($cards): array
    {
        $matchingCards = [];

        for ($i = 0; $i < count($cards); $i++) {
            switch ($cards[$i]->rank) {
                case 2:
                case 3:
                case 13:
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

    function addToDraw($i, $st, $currentPlayerId)
    {
        if ($st['fwPlayerId'] == $currentPlayerId)
            $st['draw'] += $i;
        if ($st['bwPlayerId'] == $currentPlayerId)
            $st['bwDraw'] += $i;
    }

    function getCardName($card): string
    {
        return ('The ' . $this->game->cardTypes['ranks'][$card->rank]['name'] . " of " . $this->game->cardTypes['suits'][$card->suit]['name'] . 's');
    }

    function getCardNames($cards): string
    {
        return implode(", ", array_map(fn($card) => $this->getCardName($card), $cards));
    }

    function nextPlayerId($playerId): int
    {
        $game = $this->game;

        $playerNo = $game->getPlayerNoById($playerId) + 1;
        if ($playerNo > $game->getPlayerCount()) $playerNo = 1;
        return $game->getPlayerIdByNo($playerNo);
    }

    function prevPlayerId($playerId): int
    {
        $game = $this->game;

        $playerNo = $game->getPlayerNoById($playerId) - 1;
        if ($playerNo < 1) $playerNo = $game->getPlayerCount();
        return $game->getPlayerIdByNo($playerNo);
    }
}
