<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 R-Stiker contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\RStiker\Command;

use OCA\RStiker\Service\StickerService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Rebuilds the per-sticker metadata (titles and dimensions) by scanning the
 * actual files in the sticker storage:
 *
 *   occ r-stiker:rescan
 *
 * Used to repair packs whose stickers were imported before the sizes feature
 * existed, or after files were added/removed directly in the app data folder.
 */
class RescanPacks extends Command {
	public function __construct(
		private readonly StickerService $stickerService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('r-stiker:rescan')
			->setDescription('Rebuild sticker metadata (titles, dimensions) by scanning the sticker storage');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$result = $this->stickerService->rescanStickers();

		$output->writeln(sprintf(
			'Rescanned <info>%d</info> pack(s): <info>%d</info> sticker(s), dimensions detected for <info>%d</info>.',
			$result['packs'],
			$result['stickers'],
			$result['sized']
		));

		return 0;
	}
}
