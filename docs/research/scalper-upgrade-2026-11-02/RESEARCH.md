# XAUUSD scalper upgrade: primary-source research and design constraints

Research checked on **2 November 2026, Asia/Manila**. This document separates published evidence, the application's measured behavior, and new hypotheses. It does not claim that an indicator combination makes gold scalping reliably profitable. No production strategy, email setting, or execution permission is changed by this document.

The existing RSI Gold Scalper's principal September–October diagnostic produced **141 closed trades, 55 wins, 86 losses, one position still open, and −7.32693R** after the declared spread/slippage/fee assumptions. That is a conditional one-position simulation of the present rules, not executed account P/L. Its inspected BUY/SELL split motivates studying market regimes; it does not justify making gold permanently short-only. The complete baseline assumptions and alternative policies are in the [RSI backtest report](../rsi-scalper-backtest-2026-11-02/REPORT.md).

The supplied two months have already been inspected repeatedly. Their final chronological 30% is now **development data**, even if the same calendar split is retained for comparison. A positive revised result on this file would be evidence worth investigating, not untouched validation.

## What the primary sources establish

### Gold is several connected markets, with different execution conditions

The World Gold Council's **18 August 2026** market primer estimates average 2025 global turnover at **US$361bn/day**, including London OTC activity and listed derivatives. This establishes aggregate scale, not the spread, fill quality, or attainable alpha of a retail XAUUSD account. Its figures refer to 2025, not a live November 2026 quote. [WGC, market-size primer](https://www.gold.org/goldhub/research/market-primer/gold-market-primer-market-size-and-structure).

WGC's separate trading-hubs page distinguishes London OTC, COMEX futures, and the Shanghai venues, and describes London connecting Asian and US business hours. Its chart uses **2021** volumes; its older London-share estimate must not be combined with the newer primer as though both described one current denominator. Connected gold markets are not interchangeable price feeds. [WGC, global trading hubs](https://www.gold.org/gold-market-structure/global-gold-market).

**Design inference:** develop and validate on the intended broker's timestamped bid/ask history. Use one consistent feed for every indicator timeframe. A spot-provider signal checked against a broker CFD is a cross-feed experiment; record basis, quote differences, and skipped orders. GC futures evidence cannot establish the retail broker's contract size, point value, commission, swap, or available price. Preserve explicit broker metadata and actual fills before expressing results in account currency or Philippine pesos.

### Session liquidity matters, but excluding Asia is not a universal finding

CME's historical liquidity analysis uses **Q1 2018** participation data and describes tight COMEX gold top-of-book spreads across most trading hours, with substantial Asian participation. US peak depth can be higher without implying that Asian hours are untradeable. These are historical exchange-order-book observations, not today's OTC/CFD spreads. The page does not establish an optimal retail scalping session. [CME, historical COMEX liquidity study](https://www.cmegroup.com/education/articles-and-reports/liquidity-of-comex-gold-and-silver-is-excellent-during-asia-hours).

CME's product material identifies standardized GC contracts and scheduled trading interruptions. The linked fact card carries an **October 2023** footer, so it is supporting product documentation rather than a substitute for current contract rules. The exchange's current hours page explicitly separates trading phases and publishes product/holiday schedules that can change. Those schedules concern exchange products; the broker must confirm its own XAUUSD hours. [CME, gold fact card](https://www.cmegroup.com/market-regulation/files/gold-futures-and-options-fact-card.pdf), [CME, trading and holiday hours](https://www.cmegroup.com/trading-hours.html).

**Design inference:** session restrictions should be operational hypotheses tested on broker execution costs. Do not describe a fixed UTC window as a permanent London/New York overlap: daylight-saving changes move local sessions relative to UTC. Use timezone-aware clocks, actual holiday/early-close metadata, and fresh executable quotes. A valid candle ending exactly at maintenance does not authorize a new trade during the closure. Restricting entries to a liquid period is distinct from forcibly closing an existing position at its end; both policies need explicit rules.

### Scheduled news changes volatility and execution costs

Cai, Cheung and Wong's **2001** COMEX study characterizes intraday gold-futures volatility and identifies employment, GDP, CPI, and personal-income announcements as especially influential among its tested US releases. The publisher abstract is available and was checked; it establishes announcement sensitivity, not a directional trading signal or an optimal blackout length. [Cai, Cheung and Wong, publisher record and abstract](https://onlinelibrary.wiley.com/doi/10.1002/1096-9934%28200103%2921%3A3%3C257%3A%3AAID-FUT4%3E3.0.CO%3B2-W).

Smales and Yang's **2015** study examines COMEX transaction data from **24 November 2006 to 31 December 2012**, aggregated into 30-second intervals around announcements. It links news responses to returns, volatility, spreads, and volume. The indexed author manuscript describes average responses subsiding within roughly **2½–3 minutes**; that is not proof that every episode becomes safe at minute three. Nor does it show that a 30-minute blackout is optimal. Its empirical window is 15 minutes before and 45 minutes after the event. Direct repository/publisher access returned 403 during this review; these details were checked against indexed text of the authors' manuscript, and that access limitation is preserved here. [Author manuscript at Curtin](https://espace.curtin.edu.au/bitstream/handle/20.500.11937/28039/227202.pdf?sequence=2), [publisher DOI record](https://doi.org/10.1080/13504851.2014.972538).

**Design inference:** avoid claiming that a larger candle range makes an announcement attractive to scalp. Spread widening and uncertain fills can increase at the same time. A predeclared news exclusion is a reasonable risk-control experiment. Its exact buffer is an engineering choice requiring validation, not a number established by these papers. Unscheduled news remains possible.

### Calendars must identify actual events, dates and timezones

The official BLS **2026** calendar lists Employment Situation releases on **4 September** and **2 October** and CPI releases on **11 September** and **14 October**, at **08:30 Eastern Time**. The current release-specific pages list subsequent releases and provide an updating calendar feed. This is stronger evidence than assuming the first Friday or a fixed UTC hour. A current calendar still does not prove which version was available historically; archive the calendar used by each experiment. [BLS, 2026 schedule](https://www.bls.gov/schedule/2026/home.htm), [CPI schedule](https://www.bls.gov/schedule/news_release/cpi.htm), [Employment Situation schedule](https://www.bls.gov/schedule/news_release/empsit.htm).

The Federal Reserve calendar identifies the **15–16 September** and **27–28 October 2026** meetings and links policy statements, projections when applicable, and press conferences. The September meeting page confirms the policy release at **14:00** on 16 September. A meeting date alone is not a complete blackout interval: store the statement and press-conference events separately using verified start/end coverage. [Federal Reserve, meeting calendar](https://www.federalreserve.gov/monetarypolicy/fomccalendars.htm), [September meeting materials](https://www.federalreserve.gov/monetarypolicy/fomcpresconf20260916.htm).

**Design inference:** live news coverage must have a source, update time, explicit UTC coverage interval, and event timestamps converted with the date's Eastern offset. Missing, stale, or incomplete coverage must block a news-gated entry. CPI/NFP/FOMC alone are not all relevant releases: the academic evidence also points to GDP and income data, requiring the appropriate official agencies. A backtest with no complete historical calendar must state that news protection was not tested; it must not silently label missing events as an empty calendar.

### Trend evidence is horizon-specific

The original time-series momentum research covers 58 futures/forward instruments with monthly data and a 12-month formation signal, rather than five-minute RSI entries. The authors' accompanying dataset explicitly identifies those horizons and a one-month holding period. This can motivate a trend-regime hypothesis; it cannot validate H1 EMA50/200, a particular RSI band, or a profitable M5 crossover. [AQR, original-paper dataset and methodology](https://www.aqr.com/Insights/Datasets/Time-Series-Momentum-Original-Paper-Data).

**Design inference:** test both directions conditional on a causal regime definition. The baseline sample's losing BUY book and profitable SELL book are a descriptive, period-specific observation. A predeclared long-in-uptrend/short-in-downtrend filter is more defensible to investigate than declaring that gold should always be sold. Its added value must survive a genuinely new period; the published monthly evidence does not supply its indicator parameters.

## Translate the evidence into testable constraints

| Constraint | Proposed treatment | Evidentiary limit |
|---|---|---|
| Crossovers without context | Retain the actual SMA9/21 and simple RSI14 entry logic as the control; separately test a closed higher-timeframe regime filter | A new filter is a hypothesis, not a repaired proven edge |
| Cost relative to available movement | Record spread/ATR and total assumed round-trip cost/initial risk; reject unaffordable quotes under a single declared bound | No source above establishes a universal ATR/cost cutoff |
| Session selection | Freeze one operational window, name its timezone, and compare it with the unrestricted control | Historical COMEX depth does not establish today's broker CFD session ranking |
| Finite holding | Test a declared maximum elapsed hold and/or flat-before-maintenance policy, with actual next available executable quotes | A time exit changes payoff; its exact duration is not an academic result |
| News | Require fresh, explicit calendar coverage and a predeclared buffer; keep a separate no-news-data diagnostic | No calendar means unknown news exposure, not a verified news-safe strategy |
| Stop integrity | Preserve the adverse swing/ATR stop; reduce size or skip an oversized stop | Widening stops is not evidence of improved expectancy |
| Timing | Only completed bars; enter after the signal candle closes; stop/target protection takes priority over optional market exits | Minute bars do not establish intraminute tick ordering |
| Exposure | One position, documented rejected overlaps, explicit exit policy and costs | Summing hundreds of overlapping alert outcomes is not portfolio P/L |

The existing scalper computes RSI from the last 14 gains/losses, ATR as the arithmetic average of the last 14 true ranges, and SMA9/21 crossovers; it does not use Wilder smoothing for those indicators. Preserve that baseline rather than silently changing the indicator definitions while adding filters. Its primary email path receives no position context, so the optional `CLOSE` logic is not the email strategy's historical exit policy. TP1 and TP2 must remain separate full-position experiments unless a partial-exit allocation is specified in advance.

A finite holding policy is useful to study because a strategy named scalper can otherwise hold overnight or across gaps. Its implementation must not invent a fill at a missing minute or close at a historical price known only later. If a planned deadline falls in a gap, report the delayed executable exit and gap exposure. A time limit does not guarantee a fill or bound a gap loss.

For costs, use dimensions consistently. An approximate round-trip cost in USD/oz is one full spread plus adverse slippage on both fills plus the round-trip fee; variable spreads require the relevant entry/exit quote model. Divide by initial entry-to-stop risk to express cost in R, or by M5 ATR for a volatility-normalized filter. Do not charge a full spread twice while also executing buys at ask and sells at bid. The existing CSV's minute-minimum spread remains a lower-bound proxy, and spread/ATR calculations cannot recover unavailable intraminute ask extremes.

## Validation that can support a profitability claim

Bailey, Borwein, López de Prado and Zhu's **February 2015** PBO paper explains how repeated strategy selection produces overfit backtests and why a conventional holdout alone can be unreliable in that setting. It proposes a framework including combinatorially symmetric cross-validation. This is a reason to disclose search history and evaluate selection risk; it is not permission to reuse an inspected period as fresh evidence. [PBO, authors' paper](https://www.davidhbailey.com/dhbpapers/backtest-prob.pdf).

Bailey and López de Prado's **31 July 2014** DSR paper addresses selection bias under multiple testing and non-normal returns. A credible calculation needs suitable return observations, distributional information, and the extent/dependence of the trials. A selected trade-R average, a win rate, or four named variants alone does not supply those inputs. Do not publish a fabricated DSR/PBO confidence score for this two-month exercise. [DSR, authors' paper](https://www.davidhbailey.com/dhbpapers/deflated-sharpe.pdf).

Use the following protocol for this upgrade:

1. Freeze the small candidate family and exact rules before inspecting its new results. Keep every tested rule version, including failures; distinguish strategy trials from cost/clock sensitivity cases without treating the latter as independent discoveries.
2. Keep the baseline's same M1 path, price convention, clock assumptions, closed-bar timing, spread treatment, commission/slippage model, and one-position bookkeeping. Report every changed rule explicitly.
3. Show both directions, both calendar segments, cost stress, gaps, open positions, and trade concentration. Report net expectancy, profit factor, drawdown, holding time and sample size alongside wins/losses.
4. Describe all September–October results, including the final 30%, as development. Freeze one revision before obtaining additional broker history that has not informed these choices. Do not repeatedly alter the revision after viewing that new period.
5. Use chronological walk-forward evaluation across several distinct regimes on a longer broker dataset. Keep indicator warmup and any train/validation separation causal; keep trades spanning boundaries from leaking outcomes into a training decision. Preserve actual spread/ask/tick data when available.
6. Forward-test the frozen revision on demo, recording quote-to-fill drift, fees, skips, broker stops/targets, news gating, and clock/session behavior. Compare the observed implementation with the modeled one before considering capital allocation.

The research justifies investigating cleaner regime selection, observable costs, explicit holding limits, and verified news/session handling. It does **not** justify promising profitability, promoting the best inspected variant automatically, treating a good software test as investment evidence, or increasing risk to turn a negative backtest into a positive account return.

## Source access and scope record

All external links above were checked during this research turn. WGC and CME are primary market-structure sources but have an institutional interest in their markets; their descriptions are not independent proof of a trading edge. Cai et al. was verified through the publisher's abstract, not a complete replication. Smales/Yang was checked through indexed author-manuscript passages because direct downloads were blocked. BLS and Federal Reserve pages were read directly. PBO and DSR author-hosted PDFs were read directly with their version dates. Search-result crawl dates were not substituted for study sample dates or publication dates. No third-party trading blog, social-media setup, or unexplained indicator claim is used as evidence.
