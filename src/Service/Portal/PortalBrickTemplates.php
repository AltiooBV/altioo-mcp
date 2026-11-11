<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Portal;

use Combodo\iTop\Portal\Brick\AbstractBrick;
use Combodo\iTop\Portal\Service\TemplatesProvider\TemplatesProviderInterface;
use Combodo\iTop\Portal\Service\TemplatesProvider\TemplatesProviderService;
use Combodo\iTop\Portal\Service\TemplatesProvider\TemplatesRegister;
use Combodo\iTop\Service\InterfaceDiscovery\InterfaceDiscovery;

/**
 * The templates service a brick needs to be read, without the portal that
 * renders it.
 *
 * From iTop 3.2.1 a brick resolves its template paths through a static that
 * only TemplatesProviderService's constructor sets, and LoadFromXml() goes
 * through it for any brick whose XML overrides a template. Outside the
 * portal's kernel that static is never set, and the first such brick throws -
 * which the stock portal never shows, because it overrides none.
 *
 * The real constructor wants the portal's configuration and a Symfony cache
 * pool. This one skips it and fills a fresh register itself, from the brick
 * classes only: their templates are all LoadFromXml() looks up. An empty
 * register is not enough - ManageBrick asks it for its template ids, and
 * iTop's fallback for a provider it has never heard of is a list of strings it
 * then calls GetId() on. Nothing here is rendered, so the overridden paths it
 * records are never read back.
 *
 * Loaded only where the parent class exists, which PortalPolicy checks first:
 * on 3.2.0 bricks take no templates service at all.
 *
 * @internal
 * @since 1.2.0
 */
final class PortalBrickTemplates extends TemplatesProviderService
{
	/** @noinspection PhpMissingParentConstructorInspection The parent's needs a kernel; see the class comment. */
	public function __construct()
	{
		$this->oTemplateRegister = new TemplatesRegister('unset');

		foreach (InterfaceDiscovery::GetInstance()->FindItopClasses(TemplatesProviderInterface::class) as $sProvider) {
			if (is_a($sProvider, AbstractBrick::class, true)) {
				$sProvider::RegisterTemplates($this->oTemplateRegister);
			}
		}

		// The portal's own constructor does exactly this, for the same reason.
		AbstractBrick::SetTemplatesProviderService($this);
	}
}
