<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 R-Stiker contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\RStiker\Command;

use OCA\RStiker\AppInfo\Application;
use OCA\RStiker\Service\StickerService;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Imports sticker packs (folders with PNG/GIF/JPEG/WEBP files) into the sticker
 * storage of the app. By default the "stickerpacks" folder shipped with the app
 * is used:
 *
 *   occ r-stiker:import [path]
 */
class ImportPacks extends Command {
	private const EXIT_ERROR = 1;

	public function __construct(
		private readonly StickerService $stickerService,
		private readonly IAppManager $appManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('r-stiker:import')
			->setDescription('Import sticker packs from a directory')
			->addArgument(
				'path',
				InputArgument::OPTIONAL,
				'Directory which contains one folder per sticker pack (default: the "stickerpacks" folder of the app)'
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$path = $input->getArgument('path');
		if (!is_string($path) || $path === '') {
			try {
				$path = $this->appManager->getAppPath(Application::APP_ID) . '/stickerpacks';
			} catch (AppPathNotFoundException $e) {
				$output->writeln('<error>Could not locate the app directory</error>');

				return self::EXIT_ERROR;
			}
		}

		if (!is_dir($path)) {
			$output->writeln('<error>Directory not found: ' . $path . '</error>');

			return self::EXIT_ERROR;
		}

		$imported = 0;
		$skipped = 0;
		$packs = 0;

		foreach ($this->readDirectories($path) as $packName) {
			try {
				if ($this->stickerService->getPack($packName) === null) {
					$this->stickerService->createPack($packName, $packName, '');
					$output->writeln('Created pack <info>' . $packName . '</info>');
				}
			} catch (\InvalidArgumentException $e) {
				$output->writeln('<error>Skipped pack ' . $packName . ': ' . $e->getMessage() . '</error>');
				continue;
			}
			$packs++;

			foreach ($this->readStickerFiles($path . '/' . $packName) as $fileName) {
				if ($this->stickerService->hasSticker($packName, $fileName)) {
					$skipped++;
					continue;
				}

				try {
					$this->stickerService->addSticker($packName, $path . '/' . $packName . '/' . $fileName, $fileName);
					$imported++;
				} catch (\InvalidArgumentException|\RuntimeException $e) {
					$skipped++;
					$output->writeln(
						'<comment>Skipped ' . $packName . '/' . $fileName . ': ' . $e->getMessage() . '</comment>',
						OutputInterface::VERBOSITY_VERBOSE
					);
				}
			}
		}

		$output->writeln(sprintf(
			'Imported <info>%d</info> sticker(s) into %d pack(s), skipped %d file(s).',
			$imported,
			$packs,
			$skipped
		));

		return 0;
	}

	/**
	 * @return list<string>
	 */
	private function readDirectories(string $path): array {
		$directories = [];
		foreach (scandir($path) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			if (is_dir($path . '/' . $entry)) {
				$directories[] = $entry;
			}
		}

		return $directories;
	}

	/**
	 * @return list<string>
	 */
	private function readStickerFiles(string $packPath): array {
		$files = [];
		foreach (scandir($packPath) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			if (is_file($packPath . '/' . $entry)) {
				$files[] = $entry;
			}
		}

		return $files;
	}
}
