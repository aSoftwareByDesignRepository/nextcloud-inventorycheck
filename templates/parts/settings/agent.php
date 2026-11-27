<?php
/** Synced from _shared/agent-auth — do not edit app-side. */

declare(strict_types=1);

/**
 * "AI access (MCP)" settings section — pack partial.
 *
 * Expects $agentAccess from AgentSettingsPayload::payload(). Renders
 * nothing when the user may neither mint nor see tokens (nav-gate, not a
 * disabled state). All IDs/classes use the app prefix (iv).
 *
 * @var \OCP\IL10N $l
 * @var array $agentAccess
 */

$agentAccess = is_array($agentAccess ?? null) ? $agentAccess : [];
if (($agentAccess['visible'] ?? false) !== true) {
	return;
}

$iv_state = [
	'appId' => (string)($agentAccess['appId'] ?? ''),
	'agentHeader' => (string)($agentAccess['agentHeader'] ?? ''),
	'ncUrl' => (string)($agentAccess['ncUrl'] ?? ''),
	'isAdmin' => (bool)($agentAccess['isAdmin'] ?? false),
	'canMint' => (bool)($agentAccess['canMint'] ?? false),
	'selfMintEnabled' => (bool)($agentAccess['selfMintEnabled'] ?? false),
	'tokens' => $agentAccess['tokens'] ?? [],
	'presets' => $agentAccess['presets'] ?? ['read' => [], 'act' => []],
	'allScopes' => $agentAccess['allScopes'] ?? [],
	'scopeLabels' => $agentAccess['scopeLabels'] ?? [],
];
$iv_tokens = is_array($iv_state['tokens']) ? $iv_state['tokens'] : [];
?>
<section class="iv-section iv-agent" id="iv-agent-access" aria-labelledby="iv-agent-h">
	<h2 class="iv-section-title" id="iv-agent-h"><?php p($l->t('AI access (MCP)')); ?></h2>
	<p class="iv-section-intro">
		<?php p($l->t('Connect an AI assistant to this app with a scoped token. The token works only for this app — never for your files, mail, or other apps.')); ?>
	</p>
	<div id="iv-agent-live" class="iv-visually-hidden" aria-live="polite"></div>

	<?php if ($iv_tokens === []): ?>
	<div class="iv-agent-empty" id="iv-agent-empty">
		<p class="iv-agent-empty__text"><?php p($l->t('No AI access yet.')); ?></p>
		<?php if ($iv_state['canMint']): ?>
		<button type="button" class="iv-btn iv-btn--primary" id="iv-agent-connect"><?php p($l->t('Connect your AI')); ?></button>
		<?php endif; ?>
	</div>
	<?php else: ?>
	<div class="iv-table-wrap iv-agent-tablewrap" tabindex="0" role="region" aria-labelledby="iv-agent-h">
		<table class="iv-table iv-table--cards iv-agent-table" id="iv-agent-table">
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Label')); ?></th>
					<th scope="col"><?php p($l->t('Scopes')); ?></th>
					<th scope="col"><?php p($l->t('Created')); ?></th>
					<th scope="col"><?php p($l->t('Expires')); ?></th>
					<th scope="col"><?php p($l->t('Last used')); ?></th>
					<th scope="col"><span class="iv-visually-hidden"><?php p($l->t('Actions')); ?></span></th>
				</tr>
			</thead>
			<tbody id="iv-agent-tbody">
				<?php foreach ($iv_tokens as $iv_tok): ?>
				<tr data-token-id="<?php p((string)($iv_tok['id'] ?? '')); ?>" data-token-label="<?php p((string)($iv_tok['label'] ?? '')); ?>"<?php if (($iv_tok['state'] ?? '') !== 'active'): ?> class="iv-agent-row--inactive"<?php endif; ?>>
					<td data-cell="<?php p($l->t('Label')); ?>" class="iv-agent-label-cell"><span class="iv-agent-labelcell"><span class="iv-agent-label" title="<?php p((string)($iv_tok['label'] ?? '')); ?>"><?php p((string)($iv_tok['label'] ?? '')); ?></span>
						<?php if (($iv_tok['state'] ?? '') === 'revoked'): ?>
							<span class="iv-badge iv-badge--neutral"><?php p($l->t('Revoked')); ?></span>
						<?php elseif (($iv_tok['state'] ?? '') === 'expired'): ?>
							<span class="iv-badge iv-badge--neutral"><?php p($l->t('Expired')); ?></span>
						<?php else: ?>
							<span class="iv-badge iv-badge--info"><?php p($l->t('Active')); ?></span>
						<?php endif; ?>
						<?php if ($iv_state['isAdmin'] && ($iv_tok['userId'] ?? '') !== ''): ?>
							<span class="iv-agent-owner"><?php p((string)$iv_tok['userId']); ?></span>
						<?php endif; ?></span></td>
					<td data-cell="<?php p($l->t('Scopes')); ?>" class="iv-agent-scopes-cell">
						<span class="iv-agent-scopes">
						<?php foreach (($iv_tok['scopes'] ?? []) as $iv_scope): ?>
							<span class="iv-badge iv-badge--neutral iv-agent-scope" title="<?php p((string)$iv_scope); ?>"><?php p((string)($iv_state['scopeLabels'][$iv_scope] ?? $iv_scope)); ?></span>
						<?php endforeach; ?>
						</span>
					</td>
					<td data-cell="<?php p($l->t('Created')); ?>" data-ts="<?php p((string)($iv_tok['createdAt'] ?? '')); ?>"></td>
					<td data-cell="<?php p($l->t('Expires')); ?>" data-ts="<?php p((string)($iv_tok['expiresAt'] ?? '')); ?>"></td>
					<td data-cell="<?php p($l->t('Last used')); ?>"<?php if (($iv_tok['lastUsedAt'] ?? null) === null): ?> class="iv-agent-never"<?php endif; ?> data-ts="<?php p((string)($iv_tok['lastUsedAt'] ?? '')); ?>"><?php if (($iv_tok['lastUsedAt'] ?? null) === null): ?><?php p($l->t('never')); ?><?php endif; ?></td>
					<td class="iv-row-actions">
						<?php if (($iv_tok['state'] ?? '') === 'active'): ?>
						<button type="button" class="iv-btn iv-btn--ghost iv-agent-rotate" data-token-id="<?php p((string)$iv_tok['id']); ?>"><?php p($l->t('Rotate')); ?></button>
						<button type="button" class="iv-btn iv-btn--danger iv-agent-revoke" data-token-id="<?php p((string)$iv_tok['id']); ?>" data-token-label="<?php p((string)($iv_tok['label'] ?? '')); ?>"><?php p($l->t('Revoke')); ?></button>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ($iv_state['canMint']): ?>
		<div class="iv-agent-actions">
			<button type="button" class="iv-btn iv-btn--primary" id="iv-agent-connect"><?php p($l->t('Connect your AI')); ?></button>
		</div>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<div class="iv-agent-webhooks" id="iv-agent-webhooks">
		<h3 class="iv-agent-sub-h" id="iv-agent-webhooks-h"><?php p($l->t('Webhook endpoints')); ?></h3>
		<p class="iv-form-row__hint"><?php p($l->t('Your AI assistant can register endpoints that receive a signed notification whenever it changes something here. They belong to you — test or revoke them any time.')); ?></p>
		<div class="iv-table-wrap iv-agent-tablewrap" tabindex="0" role="region" aria-labelledby="iv-agent-webhooks-h">
			<table class="iv-table iv-table--cards iv-agent-table" id="iv-agent-webhook-table">
				<thead>
					<tr>
						<th scope="col"><?php p($l->t('Endpoint')); ?></th>
						<th scope="col"><?php p($l->t('Events')); ?></th>
						<th scope="col"><?php p($l->t('State')); ?></th>
						<th scope="col"><?php p($l->t('Last delivery')); ?></th>
						<th scope="col"><span class="iv-visually-hidden"><?php p($l->t('Actions')); ?></span></th>
					</tr>
				</thead>
				<tbody id="iv-agent-webhook-tbody"></tbody>
			</table>
		</div>
	</div>

	<?php if ($iv_state['isAdmin']): ?>
	<div class="iv-form-row iv-agent-selfmint">
		<input type="hidden" name="agent_self_mint" value="0">
		<label class="iv-checkbox-label" for="iv-agent-selfmint-cb">
			<input type="checkbox" id="iv-agent-selfmint-cb" <?php if ($iv_state['selfMintEnabled']): ?>checked<?php endif; ?>>
			<?php p($l->t('Let app users create their own AI tokens')); ?>
		</label>
		<p class="iv-form-row__hint" id="iv-agent-selfmint-hint"><?php p($l->t('When off, only app administrators can connect AI assistants.')); ?></p>
	</div>
	<?php endif; ?>

	<dialog id="iv-agent-mint-dialog" class="iv-dialog iv-agent-mint-dialog" aria-labelledby="iv-agent-mint-h">
		<div class="iv-dialog__body">
			<h3 id="iv-agent-mint-h"><?php p($l->t('Connect your AI')); ?></h3>
			<form id="iv-agent-mint-form" class="iv-form-stack" novalidate>
				<div class="iv-form-row">
					<label for="iv-agent-label"><?php p($l->t('Label')); ?></label>
					<input type="text" id="iv-agent-label" name="label" maxlength="190" required autocomplete="off" placeholder="<?php p($l->t('Claude on my laptop')); ?>">
					<p class="iv-field-error" id="iv-agent-label-error" hidden></p>
				</div>
				<fieldset class="iv-form-row iv-fieldset">
					<legend><?php p($l->t('What may the AI do?')); ?></legend>
					<div class="iv-choice-cards">
						<label class="iv-choice-card">
							<input type="radio" name="preset" value="read" checked>
							<span class="iv-choice-card__copy">
								<span class="iv-choice-card__title"><?php p($l->t('Read only')); ?></span>
								<span class="iv-choice-card__hint"><?php p($l->t('see your data, never change it')); ?></span>
							</span>
						</label>
						<label class="iv-choice-card">
							<input type="radio" name="preset" value="act">
							<span class="iv-choice-card__copy">
								<span class="iv-choice-card__title"><?php p($l->t('Read + act on my behalf')); ?></span>
								<span class="iv-choice-card__hint"><?php p($l->t('read and make changes — payments and legal stays with you')); ?></span>
							</span>
						</label>
						<label class="iv-choice-card">
							<input type="radio" name="preset" value="custom">
							<span class="iv-choice-card__copy">
								<span class="iv-choice-card__title"><?php p($l->t('Choose exactly')); ?></span>
								<span class="iv-choice-card__hint"><?php p($l->t('pick each permission yourself')); ?></span>
							</span>
						</label>
					</div>
				</fieldset>
				<fieldset class="iv-form-row iv-fieldset" id="iv-agent-custom-wrap" hidden>
					<legend><?php p($l->t('Permissions')); ?></legend>
					<div class="iv-agent-chips" id="iv-agent-chips"></div>
					<p class="iv-field-error" id="iv-agent-scopes-error" hidden></p>
				</fieldset>
				<p class="iv-form-row__hint"><?php p($l->t('Valid for 1 year. The token only reaches this app.')); ?></p>
			</form>
		</div>
		<footer class="iv-dialog__footer">
			<div class="iv-actions">
				<button type="button" class="iv-btn iv-btn--secondary" id="iv-agent-mint-cancel"><?php p($l->t('Cancel')); ?></button>
				<button type="submit" form="iv-agent-mint-form" class="iv-btn iv-btn--primary" id="iv-agent-mint-submit"><?php p($l->t('Create token')); ?></button>
			</div>
		</footer>
	</dialog>

	<dialog id="iv-agent-secret-dialog" class="iv-dialog iv-agent-secret-dialog" aria-labelledby="iv-agent-secret-h">
		<div class="iv-dialog__body">
			<h3 id="iv-agent-secret-h"><?php p($l->t('Your AI is connected')); ?></h3>
			<p class="iv-form-row__hint"><?php p($l->t('Copy this config into your AI client now — the token is shown only once.')); ?></p>
			<div class="iv-form-row">
				<label for="iv-agent-secret"><?php p($l->t('Token')); ?></label>
				<div class="iv-input-group">
					<input type="password" id="iv-agent-secret" readonly autocomplete="off" spellcheck="false">
					<button type="button" class="iv-btn iv-btn--ghost" id="iv-agent-secret-reveal" aria-pressed="false"><?php p($l->t('Show')); ?></button>
					<button type="button" class="iv-btn iv-btn--ghost" id="iv-agent-secret-copy"><?php p($l->t('Copy')); ?></button>
				</div>
			</div>
			<div class="iv-form-row">
				<label for="iv-agent-config"><?php p($l->t('Client config')); ?></label>
				<textarea id="iv-agent-config" class="iv-agent-config" readonly rows="10" spellcheck="false"></textarea>
			</div>
		</div>
		<footer class="iv-dialog__footer">
			<div class="iv-actions">
				<button type="button" class="iv-btn iv-btn--secondary" id="iv-agent-secret-close"><?php p($l->t('Done')); ?></button>
				<button type="button" class="iv-btn iv-btn--primary" id="iv-agent-config-copy"><?php p($l->t('Copy config')); ?></button>
			</div>
		</footer>
	</dialog>

	<dialog id="iv-agent-revoke-dialog" class="iv-dialog" aria-labelledby="iv-agent-revoke-h">
		<div class="iv-dialog__body">
			<h3 id="iv-agent-revoke-h"><?php p($l->t('Revoke token?')); ?></h3>
			<p id="iv-agent-revoke-text"></p>
		</div>
		<footer class="iv-dialog__footer">
			<div class="iv-actions">
				<button type="button" class="iv-btn iv-btn--secondary" id="iv-agent-revoke-cancel"><?php p($l->t('Cancel')); ?></button>
				<button type="button" class="iv-btn iv-btn--danger" id="iv-agent-revoke-confirm"><?php p($l->t('Revoke')); ?></button>
			</div>
		</footer>
	</dialog>

	<script type="application/json" id="iv-agent-state"><?php
		print_unescaped(json_encode($iv_state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
	?></script>
</section>
