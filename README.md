# Pro Sites (Community Maintained Fork)

A WordPress Multisite monetization plugin for selling paid site upgrades.

## Status

- This repository is a **community-maintained fork**.
- Original plugin created and published by **WPMU DEV / Incsub**.
- Official WPMU DEV commercial support/updates are no longer active for this legacy codebase.

## Credit

Pro Sites was originally authored by WPMU DEV contributors (including Aaron Edwards and others). This fork keeps that work alive for operators who still run Pro Sites in production and need ongoing compatibility/maintenance.

## What Pro Sites Does

Pro Sites lets you monetize a WordPress Multisite network by offering subscription levels per site.

Core capabilities include:

- Unlimited paid levels (monthly/quarterly/annual, etc.)
- Free vs paid site tiers
- Prorated upgrades/downgrades
- Recurring billing support by gateway
- Coupons and free trials
- Checkout and pricing table UI
- Per-level restrictions and feature access
- Network-level reporting and subscription stats

## Feature Modules

Pro Sites includes modular controls so you can enable only what you need:

- Advertising controls
- Bulk upgrades
- BuddyPress feature limits
- Limit publishing
- Pay to Blog
- Post/Page quotas
- Premium plugins access
- Premium support access
- Premium themes access
- Restrict XML-RPC
- Unfiltered HTML controls
- Upload quota controls
- Pro widget / upgrade prompts

## Payment Gateways

Depending on your configuration/version, available gateways include:

- Manual payments
- PayPal (legacy modules)
- Stripe (legacy module)
- Square (this fork: recurring subscription gateway work in progress/active development)

## Recent Fork Changes

This fork has ongoing modernization work for real-world production use, including:

- PHP 8.x compatibility fixes (including csstidy + Taxamo signature updates)
- Session/caching behavior improvements for better page-cache compatibility
- Square recurring gateway implementation and staging validation work
- Removal of obsolete WPMU DEV dashboard nag/banner integration

## Installation (Multisite)

1. Place plugin in your multisite plugins directory.
2. Go to **Network Admin → Plugins**.
3. **Network Activate** Pro Sites.
4. Configure under **Network Admin → Pro Sites**.

## Basic Setup Flow

1. Create subscription levels
2. Enable needed modules
3. Configure gateway(s)
4. Set checkout/pricing display
5. Test signup/upgrade/cancel flows in staging before production

## Operational Notes

- Pro Sites subscriptions are applied **per site** (not globally per user).
- On active production networks, always test gateway and lifecycle changes in staging first.
- Legacy gateway behavior may depend on older API assumptions; validate webhooks and renewal/cancel paths end-to-end.

## Legacy References

- Translation project (legacy): https://github.com/wpmudev/translations

---

If you maintain a Pro Sites network and need practical fixes, treat this fork as an operations-first codebase: stable upgrades, reproducible testing, and clear change history.
