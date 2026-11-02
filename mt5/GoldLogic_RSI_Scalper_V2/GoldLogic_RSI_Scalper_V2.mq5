#property copyright "GoldLogic research port"
#property version   "2.00"
#property strict
#property description "RSI Gold Scalper V2: closed M5 trend-session research model. Tester/demo only."

#include <Trade/Trade.mqh>
#include "ScalperCore.mqh"

enum GLBrokerDstRule { GL_FIXED=0, GL_US=1, GL_EU=2 };
input bool            InpConfirmBrokerClock=false;
input int             InpBrokerWinterUtcMinutes=180;
input int             InpBrokerSummerUtcMinutes=180;
input GLBrokerDstRule InpBrokerDstRule=GL_FIXED;
input double          InpRiskPercent=0.25;
input double          InpCommissionPerLotRoundTurn=0.0;
input ulong           InpMagic=2601102;
input uint            InpMaxDeviationPoints=10;
input int             InpHistoryBars=4096;
input bool            InpWriteSignalLog=true;

// Frozen strategy parameters live in ScalperCore.mqh. No optimizer-specific rules.
CTrade g_trade;
datetime g_processed_open=0;
string g_lock_key="";
bool g_halted=false;
bool g_in_flight=false;
double g_expected_sl=0.0,g_expected_tp=0.0;
int g_log=INVALID_HANDLE;

bool GLTesting() { return (bool)MQLInfoInteger(MQL_TESTER); }

void GLMessage(const string text) { Print("GoldLogic V2: ",text); }

bool GLAccountAllowed()
{
   return GLTesting() || AccountInfoInteger(ACCOUNT_TRADE_MODE)==ACCOUNT_TRADE_MODE_DEMO;
}

bool GLClock(datetime &now_utc,datetime &now_server,string &reason)
{
   now_server=GLTesting()?TimeCurrent():TimeTradeServer();
   if(!GLBrokerToUtc(now_server,InpBrokerWinterUtcMinutes,InpBrokerSummerUtcMinutes,
                     (int)InpBrokerDstRule,now_utc))
   { reason="Server clock cannot be mapped unambiguously to UTC."; return false; }
   if(!GLTesting())
   {
      datetime wall=TimeGMT();
      if(wall<=0 || MathAbs((double)(wall-now_utc))>90.0)
      { reason="Configured broker UTC clock disagrees with this computer. Check offset/DST and Windows time."; return false; }
      now_utc=wall;
   }
   return true;
}

bool GLTickFresh(const MqlTick &tick,const datetime now_utc,string &reason)
{
   datetime utc=0;
   if(!GLBrokerToUtc(tick.time,InpBrokerWinterUtcMinutes,InpBrokerSummerUtcMinutes,
                     (int)InpBrokerDstRule,utc) || utc>now_utc || now_utc-utc>90)
   { reason="Quote is future-dated, stale, or has an unresolved clock."; return false; }
   if(!MathIsValidNumber(tick.bid) || !MathIsValidNumber(tick.ask) || tick.bid<=0 || tick.ask<tick.bid)
   { reason="Invalid broker bid/ask."; return false; }
   return true;
}

bool GLSymbolBusy()
{
   // Block all owners, including manual trades, on both hedging and netting accounts.
   for(int i=PositionsTotal()-1;i>=0;i--)
      if(PositionGetSymbol(i)==_Symbol) return true;
   for(int i=OrdersTotal()-1;i>=0;i--)
      if(OrderGetTicket(i)>0 && OrderGetString(ORDER_SYMBOL)==_Symbol) return true;
   return false;
}

void GLCheckProtection()
{
   if(!GLAccountAllowed()) return;
   for(int i=PositionsTotal()-1;i>=0;i--)
   {
      ulong ticket=PositionGetTicket(i);
      if(ticket==0 || PositionGetString(POSITION_SYMBOL)!=_Symbol ||
         (ulong)PositionGetInteger(POSITION_MAGIC)!=InpMagic) continue;
      double entry=PositionGetDouble(POSITION_PRICE_OPEN),sl=PositionGetDouble(POSITION_SL),tp=PositionGetDouble(POSITION_TP);
      bool buy=PositionGetInteger(POSITION_TYPE)==POSITION_TYPE_BUY;
      bool protected_position=sl>0 && tp>0 && (buy?(sl<entry && entry<tp):(tp<entry && entry<sl));
      double grid=SymbolInfoDouble(_Symbol,SYMBOL_TRADE_TICK_SIZE);
      if(g_expected_sl>0 && grid>0)
         protected_position=protected_position && MathAbs(sl-g_expected_sl)<grid*0.5 && MathAbs(tp-g_expected_tp)<grid*0.5;
      if(protected_position) continue;
      g_halted=true;
      GLMessage("HALT: own position has missing/invalid original protection; requesting emergency close.");
      bool sent=g_trade.PositionClose(ticket,InpMaxDeviationPoints);
      uint code=g_trade.ResultRetcode();
      GLMessage(StringFormat("Emergency close ticket=%I64u sent=%s retcode=%u %s",ticket,
                             sent?"true":"false",code,g_trade.ResultRetcodeDescription()));
      // An unconfirmed close is retried on later ticks; the halt never silently clears.
   }
}

bool GLLoadWindow(MqlRates &window[],datetime &latest_server_open,string &reason)
{
   if(!(bool)SeriesInfoInteger(_Symbol,PERIOD_M5,SERIES_SYNCHRONIZED))
   { reason="M5 history is still synchronizing."; return false; }
   MqlRates raw[];
   ArraySetAsSeries(raw,false);
   int count=CopyRates(_Symbol,PERIOD_M5,1,InpHistoryBars,raw);
   if(count<203) { reason="Need at least 203 completed active-session M5 bars."; return false; }
   latest_server_open=raw[count-1].time;
   ArrayResize(window,203);
   ArraySetAsSeries(window,false);
   int filled=0;
   for(int i=count-1;i>=0 && filled<203;i--)
   {
      datetime open_utc=0;
      bool mapped=GLBrokerToUtc(raw[i].time,InpBrokerWinterUtcMinutes,InpBrokerSummerUtcMinutes,
                               (int)InpBrokerDstRule,open_utc);
      if(!mapped)
      {
         if(i==count-1) { reason="Latest bar has an ambiguous broker clock."; return false; }
         // DST-overlap/nonexistent bars cannot be guessed into active history.
         continue;
      }
      if(!GLGoldOpen(open_utc) || !GLGoldOpen(open_utc+299)) continue;
      window[202-filled]=raw[i];
      window[202-filled].time=open_utc;
      filled++;
   }
   if(filled!=203) { reason="Insufficient active-session M5 history after session/clock filtering."; return false; }
   datetime newest_utc=0;
   if(!GLBrokerToUtc(latest_server_open,InpBrokerWinterUtcMinutes,InpBrokerSummerUtcMinutes,
                     (int)InpBrokerDstRule,newest_utc) || window[202].time!=newest_utc)
   { reason="Latest completed broker bar is outside the regular gold session."; return false; }
   return true;
}

bool GLAlreadyEntered(const datetime since_server,const datetime now_server,string &reason)
{
   if(!HistorySelect(since_server,now_server))
   { reason="Cannot verify entry history; blocking this candle."; return true; }
   for(int i=HistoryDealsTotal()-1;i>=0;i--)
   {
      ulong deal=HistoryDealGetTicket(i);
      if(deal==0) { reason="Cannot read selected deal history."; return true; }
      if(HistoryDealGetString(deal,DEAL_SYMBOL)!=_Symbol ||
         (ulong)HistoryDealGetInteger(deal,DEAL_MAGIC)!=InpMagic) continue;
      long kind=HistoryDealGetInteger(deal,DEAL_ENTRY);
      if(kind==DEAL_ENTRY_IN || kind==DEAL_ENTRY_INOUT)
      { reason="An entry already exists for this candle."; return true; }
   }
   return false;
}

bool GLClaimCandle(const datetime decision)
{
   if(GLTesting()) return true;
   // Atomic terminal-global lock prevents duplicate attempts after restart or on another chart.
   double previous=GlobalVariableGet(g_lock_key);
   if(previous>=(double)decision) return false;
   if(!GlobalVariableSetOnCondition(g_lock_key,(double)decision,previous)) return false;
   GlobalVariablesFlush();
   return true;
}

double GLGridPrice(const double price,const double grid,const bool up)
{
   double units=price/grid;
   double rounded=up?MathCeil(units-1e-9):MathFloor(units+1e-9);
   return NormalizeDouble(rounded*grid,(int)SymbolInfoInteger(_Symbol,SYMBOL_DIGITS));
}

bool GLFillMode(ENUM_ORDER_TYPE_FILLING &fill)
{
   long execution=SymbolInfoInteger(_Symbol,SYMBOL_TRADE_EXEMODE);
   long modes=SymbolInfoInteger(_Symbol,SYMBOL_FILLING_MODE);
   if(execution==SYMBOL_TRADE_EXECUTION_INSTANT || execution==SYMBOL_TRADE_EXECUTION_REQUEST ||
      (modes & SYMBOL_FILLING_FOK)!=0) { fill=ORDER_FILLING_FOK; return true; }
   if((modes & SYMBOL_FILLING_IOC)!=0) { fill=ORDER_FILLING_IOC; return true; }
   if(execution!=SYMBOL_TRADE_EXECUTION_MARKET) { fill=ORDER_FILLING_RETURN; return true; }
   return false;
}

bool GLRiskVolume(const ENUM_ORDER_TYPE type,const double quote,const double stop,double &volume,string &reason)
{
   double minimum=SymbolInfoDouble(_Symbol,SYMBOL_VOLUME_MIN),maximum=SymbolInfoDouble(_Symbol,SYMBOL_VOLUME_MAX);
   double step=SymbolInfoDouble(_Symbol,SYMBOL_VOLUME_STEP),point=SymbolInfoDouble(_Symbol,SYMBOL_POINT);
   double equity=AccountInfoDouble(ACCOUNT_EQUITY);
   if(minimum<=0 || maximum<minimum || step<=0 || point<=0 || !MathIsValidNumber(equity) || equity<=0)
   { reason="Invalid broker volume/point/equity metadata."; return false; }
   // Reserve the configured deviation on entry; gaps and Market Execution can exceed it.
   double worst=quote+(type==ORDER_TYPE_BUY?1.0:-1.0)*InpMaxDeviationPoints*point;
   double pnl=0;
   if(worst<=0 || !OrderCalcProfit(type,_Symbol,minimum,worst,stop,pnl) || !MathIsValidNumber(pnl) || pnl>=0)
   { reason="Cannot calculate account-currency stop risk."; return false; }
   double budget=equity*InpRiskPercent/100.0;
   double loss_per_lot=(-pnl/minimum)+InpCommissionPerLotRoundTurn;
   double raw=MathMin(maximum,budget/loss_per_lot);
   // Never round an unaffordable size up to the minimum lot.
   volume=NormalizeDouble(MathFloor(raw/step+1e-10)*step,8);
   if(volume<minimum-1e-10 || volume<=0)
   { reason="Minimum broker lot exceeds the configured risk budget."; return false; }
   double checked_pnl=0;
   if(!OrderCalcProfit(type,_Symbol,volume,worst,stop,checked_pnl) || !MathIsValidNumber(checked_pnl) ||
      checked_pnl>=0 || -checked_pnl+volume*InpCommissionPerLotRoundTurn>budget+1e-8)
   { reason="Rounded volume does not fit the stop-risk budget."; return false; }
   return true;
}

bool GLBuildRequest(const GLSignal &signal,const MqlTick &tick,MqlTradeRequest &request,string &reason)
{
   double grid=SymbolInfoDouble(_Symbol,SYMBOL_TRADE_TICK_SIZE),point=SymbolInfoDouble(_Symbol,SYMBOL_POINT);
   if(grid<=0 || point<=0) { reason="Missing broker price-grid metadata."; return false; }
   bool buy=signal.side==1;
   double quote=buy?tick.ask:tick.bid;
   double planned_risk=signal.side*(signal.entry-signal.stop);
   if(planned_risk<=0 || MathAbs(quote-signal.entry)>0.1*planned_risk ||
      (buy?!(signal.stop<quote && quote<signal.target):!(signal.target<quote && quote<signal.stop)))
   { reason="Executable quote has moved beyond 0.1R or outside the original SL/TP."; return false; }
   // Legal broker ticks: stop outward and target inward, both conservatively.
   double sl=GLGridPrice(signal.stop,grid,!buy),tp=GLGridPrice(signal.target,grid,!buy);
   double distance=(double)SymbolInfoInteger(_Symbol,SYMBOL_TRADE_STOPS_LEVEL)*point;
   bool legal=buy?(sl<tick.bid && tp>tick.bid && tick.bid-sl>=distance && tp-tick.bid>=distance):
                  (sl>tick.ask && tp<tick.ask && sl-tick.ask>=distance && tick.ask-tp>=distance);
   if(!legal || (buy?!(sl<quote && quote<tp):!(tp<quote && quote<sl)))
   { reason="Original bracket cannot meet this broker's price/stop-distance rules."; return false; }
   ENUM_ORDER_TYPE type=buy?ORDER_TYPE_BUY:ORDER_TYPE_SELL;
   double volume=0;
   if(!GLRiskVolume(type,quote,sl,volume,reason)) return false;
   ENUM_ORDER_TYPE_FILLING fill;
   if(!GLFillMode(fill)) { reason="No compatible broker filling policy."; return false; }
   ZeroMemory(request);
   request.action=TRADE_ACTION_DEAL;
   request.symbol=_Symbol;
   request.magic=InpMagic;
   request.volume=volume;
   request.type=type;
   request.price=quote;
   request.sl=sl;
   request.tp=tp;
   request.deviation=InpMaxDeviationPoints;
   request.type_filling=fill;
   request.type_time=ORDER_TIME_GTC;
   request.comment="GL RSI V2 full TP1";
   return true;
}

void GLLogSignal(const GLSignal &signal,const string status)
{
   if(g_log==INVALID_HANDLE) return;
   FileWrite(g_log,TimeToString(signal.decision_utc,TIME_DATE|TIME_SECONDS),_Symbol,signal.side,
             DoubleToString(signal.entry,4),DoubleToString(signal.stop,4),DoubleToString(signal.target,4),
             DoubleToString(signal.rsi,2),DoubleToString(signal.atr,4),
             DoubleToString(signal.trend_now,8),DoubleToString(signal.trend_prior,8),status);
   FileFlush(g_log);
}

int OnInit()
{
   if(!GLAccountAllowed()) { GLMessage("This research build supports Strategy Tester and demo accounts only."); return INIT_FAILED; }
   if(!InpConfirmBrokerClock) { GLMessage("Confirm broker historical UTC offsets/DST, then set InpConfirmBrokerClock=true."); return INIT_PARAMETERS_INCORRECT; }
   if(_Period!=PERIOD_M5) { GLMessage("Select the M5 timeframe."); return INIT_PARAMETERS_INCORRECT; }
   if(InpRiskPercent<=0 || InpRiskPercent>5 || !MathIsValidNumber(InpRiskPercent) ||
      InpCommissionPerLotRoundTurn<0 || !MathIsValidNumber(InpCommissionPerLotRoundTurn) ||
      InpMagic==0 || InpHistoryBars<203 || InpHistoryBars>20000 ||
      MathAbs(InpBrokerWinterUtcMinutes)>840 || MathAbs(InpBrokerSummerUtcMinutes)>840 ||
      (int)InpBrokerDstRule<0 || (int)InpBrokerDstRule>2 ||
      (InpBrokerDstRule==GL_FIXED && InpBrokerWinterUtcMinutes!=InpBrokerSummerUtcMinutes) ||
      (InpBrokerDstRule!=GL_FIXED && InpBrokerSummerUtcMinutes-InpBrokerWinterUtcMinutes!=60))
   { GLMessage("Invalid risk/history/clock inputs. DST modes require summer=winter+60 minutes."); return INIT_PARAMETERS_INCORRECT; }
   if(SymbolInfoString(_Symbol,SYMBOL_CURRENCY_BASE)!="XAU" || SymbolInfoString(_Symbol,SYMBOL_CURRENCY_PROFIT)!="USD" ||
      SymbolInfoInteger(_Symbol,SYMBOL_CHART_MODE)!=SYMBOL_CHART_MODE_BID)
   { GLMessage("Use the broker's XAU/USD gold symbol with Bid-based candles."); return INIT_PARAMETERS_INCORRECT; }
   g_trade.SetExpertMagicNumber(InpMagic);
   g_trade.SetDeviationInPoints(InpMaxDeviationPoints);
   g_trade.SetTypeFillingBySymbol(_Symbol);
   g_trade.SetAsyncMode(false);
   if(!GLTesting())
   {
      string identity=AccountInfoString(ACCOUNT_SERVER)+":"+(string)AccountInfoInteger(ACCOUNT_LOGIN)+":"+_Symbol+":"+(string)InpMagic;
      uint hash=2166136261;
      for(int i=0;i<StringLen(identity);i++) hash=(hash^(uint)StringGetCharacter(identity,i))*16777619;
      g_lock_key="GLRSIV2."+(string)AccountInfoInteger(ACCOUNT_LOGIN)+"."+(string)hash;
      if(!GlobalVariableCheck(g_lock_key) && GlobalVariableSet(g_lock_key,0)==0)
      { GLMessage("Cannot create the persistent candle lock."); return INIT_FAILED; }
   }
   if(InpWriteSignalLog)
   {
      string file="GoldLogic_RSI_V2_"+_Symbol+"_"+(string)InpMagic+".csv";
      g_log=FileOpen(file,FILE_WRITE|FILE_CSV|FILE_ANSI,',');
      if(g_log!=INVALID_HANDLE)
         FileWrite(g_log,"decision_utc","symbol","side","entry","stop","tp1","rsi","atr","trend_now","trend_prior","status");
      else GLMessage("CSV logging unavailable; see the Experts/Journal log.");
   }
   GLMessage("Initialized research model: M5, full original SL/TP1; no fixed time exit. Broker clock is operator-confirmed.");
   return INIT_SUCCEEDED;
}

void OnTick()
{
   if(!GLAccountAllowed()) return;
   GLCheckProtection();
   if(g_halted || g_in_flight) return;
   if(!GLTesting() && !(bool)TerminalInfoInteger(TERMINAL_CONNECTED)) return;
   datetime utc=0,server=0;
   string reason="";
   if(!GLClock(utc,server,reason)) { Comment("GoldLogic V2: ",reason); return; }
   if(!GLGoldOpen(utc) || !GLEntryWindow(utc)) { Comment("GoldLogic V2: outside entry session."); return; }
   MqlTick tick={};
   if(!SymbolInfoTick(_Symbol,tick) || !GLTickFresh(tick,utc,reason)) return;
   if((bool)SymbolInfoInteger(_Symbol,SYMBOL_SUBSCRIPTION_DELAY)) return;
   datetime latest=iTime(_Symbol,PERIOD_M5,1);
   if(latest==0 || latest==g_processed_open) return;
   MqlRates bars[];
   if(!GLLoadWindow(bars,latest,reason)) { Comment("GoldLogic V2: ",reason); return; }
   GLSignal signal;
   if(!GLEvaluate(bars,signal,reason)) { Comment("GoldLogic V2: ",reason); return; }
   if(signal.decision_utc>utc || utc-signal.decision_utc>=180)
   { Comment("GoldLogic V2: latest closed signal candle is stale/future."); return; }
   g_processed_open=latest;
   if(!GLClaimCandle(signal.decision_utc)) { GLLogSignal(signal,"Already processed (persistent lock)"); return; }
   if(signal.side==0) { GLLogSignal(signal,reason); return; }
   if(GLSymbolBusy()) { GLLogSignal(signal,"Blocked: existing symbol position/order"); return; }
   if(GLAlreadyEntered(latest+300,server,reason)) { GLLogSignal(signal,reason); return; }
   if(!(bool)MQLInfoInteger(MQL_TRADE_ALLOWED) || !(bool)AccountInfoInteger(ACCOUNT_TRADE_ALLOWED) ||
      !(bool)AccountInfoInteger(ACCOUNT_TRADE_EXPERT) || !(bool)TerminalInfoInteger(TERMINAL_TRADE_ALLOWED))
   { GLLogSignal(signal,"Algo trading is disabled"); return; }
   // Refresh the executable quote after history/indicator work.
   if(!GLClock(utc,server,reason) || !SymbolInfoTick(_Symbol,tick) || !GLTickFresh(tick,utc,reason) ||
      utc<signal.decision_utc || utc-signal.decision_utc>=180 || !GLEntryWindow(utc) || !GLGoldOpen(utc))
   { GLLogSignal(signal,"Quote/signal expired during evaluation"); return; }
   MqlTradeRequest request={};
   if(!GLBuildRequest(signal,tick,request,reason)) { GLLogSignal(signal,reason); return; }
   MqlTradeCheckResult check={};
   // OrderCheck reports success as 0 on MT5 (OrderSend uses DONE=10009).
   if(!OrderCheck(request,check) || (check.retcode!=0 && check.retcode!=TRADE_RETCODE_DONE))
   { GLLogSignal(signal,StringFormat("OrderCheck rejected: %u %s",check.retcode,check.comment)); return; }
   MqlTick final_tick={};
   if(!GLClock(utc,server,reason) || !SymbolInfoTick(_Symbol,final_tick) || !GLTickFresh(final_tick,utc,reason) ||
      utc<signal.decision_utc || utc-signal.decision_utc>=180 || !GLEntryWindow(utc) || !GLGoldOpen(utc))
   { GLLogSignal(signal,"Quote/signal expired during OrderCheck"); return; }
   // Do not submit a sizing/stop check for a quote that changed during the check.
   // One attempt per candle: a changed quote is skipped rather than chased.
   if(final_tick.bid!=tick.bid || final_tick.ask!=tick.ask)
   { GLLogSignal(signal,"Quote changed during OrderCheck; candle skipped"); return; }
   if(GLSymbolBusy()) { GLLogSignal(signal,"Symbol became busy before submission"); return; }
   g_in_flight=true; // An uncertain outcome never triggers a resubmission for this candle.
   g_expected_sl=request.sl;
   g_expected_tp=request.tp;
   MqlTradeResult result={};
   bool sent=OrderSend(request,result);
   string outcome=StringFormat("OrderSend=%s retcode=%u deal=%I64u order=%I64u price=%.5f volume=%.8f %s",
                              sent?"true":"false",result.retcode,result.deal,result.order,result.price,result.volume,result.comment);
   GLMessage(outcome);
   GLLogSignal(signal,outcome);
   if(result.retcode==TRADE_RETCODE_DONE || result.retcode==TRADE_RETCODE_DONE_PARTIAL)
   {
      // Actual fills are reported by OnTradeTransaction, never inferred from bool sent.
      GLCheckProtection();
      g_in_flight=false;
   }
   else if(result.retcode==TRADE_RETCODE_PLACED || result.retcode==TRADE_RETCODE_TIMEOUT || result.retcode==TRADE_RETCODE_CONNECTION)
   { g_halted=true; GLMessage("Uncertain/pending order outcome: entries halted. Inspect positions/orders and Journal before restarting."); }
   else g_in_flight=false;
}

void OnTradeTransaction(const MqlTradeTransaction &transaction,const MqlTradeRequest &request,const MqlTradeResult &result)
{
   if(transaction.type!=TRADE_TRANSACTION_DEAL_ADD || transaction.deal==0) return;
   if(!HistoryDealSelect(transaction.deal)) return;
   if(HistoryDealGetString(transaction.deal,DEAL_SYMBOL)!=_Symbol ||
      (ulong)HistoryDealGetInteger(transaction.deal,DEAL_MAGIC)!=InpMagic) return;
   GLMessage(StringFormat("Confirmed deal=%I64u entry_kind=%d volume=%.8f price=%.5f",transaction.deal,
                         (int)HistoryDealGetInteger(transaction.deal,DEAL_ENTRY),
                         HistoryDealGetDouble(transaction.deal,DEAL_VOLUME),HistoryDealGetDouble(transaction.deal,DEAL_PRICE)));
   GLCheckProtection();
}

void OnDeinit(const int reason)
{
   if(g_log!=INVALID_HANDLE) FileClose(g_log);
   Comment("");
}
