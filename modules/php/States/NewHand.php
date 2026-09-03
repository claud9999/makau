<?php

declare(strict_types=1);

namespace Bga\Games\makaucloudnein\States;

use Bga\Games\makaucloudnein\Game;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;

class NewHand extends GameState
{
  public function __construct(protected Game $game)
  {
    parent::__construct(
      $game,
      id: 2,
      type: StateType::GAME,
      updateGameProgression: true,
    );
  }

  // The action we do when entering the state
  public function onEnteringState()
  {
    $game = $this->game;

    $game->cards->moveAllItemsInLocation(null, "deck");

    $game->cards->shuffle('deck');

    $players = $game->loadPlayersBasicInfos();
    foreach ($players as $player_id => $player) {
      $cards = $game->cards->pickItems(5, 'deck', $player_id);
      $this->bga->notify->player($player_id, 'newHand', '', array('cards' => $cards));
    }

    $game->cards->pickItem('deck', 'discard');
    $game->cards->pickItem('deck', 'discard');
    $game->cards->pickItem('deck', 'discard');
    $game->cards->pickItem('deck', 'discard');
    $game->cards->pickItem('deck', 'discard');
    $game->cards->pickItem('deck', 'discard');

    $game->debug("Discard pile now has cards: " . json_encode($game->getCardNames($game->cards->getItemsInLocation('discard'))));

    while ($game->isSpecial($game->cards->getItemOnTop('discard'))) {
      $game->cards->pickItemForLocation('deck', 'discard');
    }

    $first_player = (int) $this->game->getActivePlayerId();
    $this->game->gamestate->changeActivePlayer($first_player);

    return PlayerTurn::class;
  }
}
