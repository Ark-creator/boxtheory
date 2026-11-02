#property strict
#property version "1.00"
#property description "Pure GoldLogic strategy/time assertions. No trading or account calls."

#include "ScalperCore.mqh"

int gl_checks=0;
int gl_failures=0;

void GLCheck(bool condition,string label)
  {
   gl_checks++;
   if(!condition)
     {
      gl_failures++;
      Print("FAIL: ",label);
     }
  }

void GLNear(double actual,double expected,string label,double tolerance=1e-8)
  {
   GLCheck(MathIsValidNumber(actual) && MathAbs(actual-expected)<=tolerance,label);
  }

bool GLSynthetic(MqlRates &bars[],datetime decision,bool mirror=false,bool countertrend=false)
  {
   ArrayResize(bars,GL_REQUIRED_BARS);
   ArraySetAsSeries(bars,false);
   datetime cursor=(datetime)((long)decision-300);
   int found=0;
   int attempts=0;
   while(found<GL_REQUIRED_BARS && attempts<10000)
     {
      if(GLGoldOpen(cursor) && GLGoldOpen((datetime)((long)cursor+299)))
        {
         int index=GL_REQUIRED_BARS-1-found;
         ZeroMemory(bars[index]);
         bars[index].time=cursor;
         found++;
        }
      cursor=(datetime)((long)cursor-300);
      attempts++;
     }
   if(found!=GL_REQUIRED_BARS)
      return false;
   // Same fixed synthetic crossover as the PHP unit fixture. No market tuning.
   for(int i=0;i<GL_REQUIRED_BARS;i++)
     {
      double price=2000.0+0.01*i+2.0*MathSin((i+20)*0.2)+(i%2!=0 ? -0.8 : 0.8);
      if(countertrend && i<179)
         price=2050.0-i*0.2;
      if(mirror)
         price=4000.0-price;
      bars[i].open=price;
      bars[i].high=price+1.0;
      bars[i].low=price-1.0;
      bars[i].close=price;
     }
   return true;
  }

void GLTestCalendar()
  {
   GLCheck(GLNthSunday(2026,3,2)==8,"US second Sunday March");
   GLCheck(GLNthSunday(2026,11,1)==1,"US first Sunday November");
   GLCheck(GLLastSunday(2026,3)==29,"EU last Sunday March");
   GLCheck(GLLastSunday(2026,10)==25,"EU last Sunday October");
   GLCheck(!GLGoldOpen(D'2026.11.01 22:59:59'),"Winter Sunday before NY open");
   GLCheck(GLGoldOpen(D'2026.11.01 23:00:00'),"Winter Sunday NY open");
   GLCheck(!GLGoldOpen(D'2026.06.07 21:59:59'),"Summer Sunday before NY open");
   GLCheck(GLGoldOpen(D'2026.06.07 22:00:00'),"Summer Sunday NY open");
   GLCheck(GLGoldOpen(D'2026.10.30 20:59:59'),"Summer Friday before NY close");
   GLCheck(!GLGoldOpen(D'2026.10.30 21:00:00'),"Summer Friday NY close");
   GLCheck(GLGoldOpen(D'2026.11.06 21:59:59'),"Winter Friday before NY close");
   GLCheck(!GLGoldOpen(D'2026.11.06 22:00:00'),"Winter Friday NY close");
   GLCheck(!GLGoldOpen(D'2026.06.01 21:30:00'),"Summer daily NY maintenance");
   GLCheck(GLGoldOpen(D'2026.06.01 22:00:00'),"Summer maintenance ends");
   GLCheck(!GLGoldOpen(D'2026.11.02 22:30:00'),"Winter daily NY maintenance");
   GLCheck(GLGoldOpen(D'2026.11.02 23:00:00'),"Winter maintenance ends");
   GLCheck(!GLEntryWindow(D'2026.11.02 06:59:59'),"Entry before07UTC");
   GLCheck(GLEntryWindow(D'2026.11.02 07:00:00'),"Entry begins07UTC");
   GLCheck(GLEntryWindow(D'2026.11.02 16:59:59'),"Entry before17UTC");
   GLCheck(!GLEntryWindow(D'2026.11.02 17:00:00'),"Entry ends17UTC");
   GLCheck(!GLEntryWindow(D'2026.11.01 12:00:00'),"Sunday is not entry day");
   GLCheck(!GLGoldOpen(D'2006.12.29 12:00:00'),"Unsupported year fails closed");
   datetime utc=0;
   GLCheck(GLBrokerToUtc(D'2026.06.01 12:00:00',180,180,0,utc) && utc==D'2026.06.01 09:00:00',"Fixed server UTC+3");
   GLCheck(GLBrokerToUtc(D'2026.06.01 12:00:00',120,180,1,utc) && utc==D'2026.06.01 09:00:00',"US broker summer offset");
   GLCheck(GLBrokerToUtc(D'2026.12.01 12:00:00',120,180,1,utc) && utc==D'2026.12.01 10:00:00',"US broker winter offset");
   GLCheck(GLBrokerToUtc(D'2026.03.08 08:59:59',120,180,1,utc) && utc==D'2026.03.08 06:59:59',"Before US spring transition");
   GLCheck(!GLBrokerToUtc(D'2026.03.08 09:30:00',120,180,1,utc) && utc==0,"US nonexistent server hour rejected");
   GLCheck(GLBrokerToUtc(D'2026.03.08 10:00:00',120,180,1,utc) && utc==D'2026.03.08 07:00:00',"After US spring transition");
   GLCheck(!GLBrokerToUtc(D'2026.11.01 08:30:00',120,180,1,utc) && utc==0,"US ambiguous server hour rejected");
   GLCheck(GLBrokerToUtc(D'2026.03.29 02:59:59',120,180,2,utc) && utc==D'2026.03.29 00:59:59',"Before EU spring transition");
   GLCheck(!GLBrokerToUtc(D'2026.03.29 03:30:00',120,180,2,utc) && utc==0,"EU nonexistent server hour rejected");
   GLCheck(GLBrokerToUtc(D'2026.03.29 04:00:00',120,180,2,utc) && utc==D'2026.03.29 01:00:00',"After EU spring transition");
   GLCheck(!GLBrokerToUtc(D'2026.10.25 03:30:00',120,180,2,utc) && utc==0,"EU ambiguous server hour rejected");
   GLCheck(GLBrokerToUtc(D'2026.06.01 12:00:00',180,180,1,utc) && utc==D'2026.06.01 09:00:00',"Equal DST offsets remain uniquely invertible");
   GLCheck(!GLBrokerToUtc(D'2026.06.01 12:00:00',120,180,3,utc),"Unknown DST rule rejected");
   GLCheck(!GLBrokerToUtc(D'2026.06.01 12:00:00',900,180,0,utc),"Invalid offset rejected");
  }

void GLTestMath()
  {
   GLNear(GLPhpRound(4124.36155,4),4124.3616,"PHP external entry tie1");
   GLNear(GLPhpRound(4188.29635,4),4188.2964,"PHP external entry tie2");
   GLNear(GLPhpRound(4383.70505,4),4383.7051,"PHP external entry tie3");
   GLNear(GLPhpRound(-4124.36155,4),-4124.3616,"Negative half away from zero");
   GLNear(GLPhpRound(0.285,8),0.285,"Decimal-grid recovery");
   MqlRates bars[];
   GLSignal signal;
   string reason;
   GLCheck(GLSynthetic(bars,D'2026.11.02 12:15:00'),"Build PHP synthetic fixture");
   GLCheck(GLEvaluate(bars,signal,reason) && signal.side==1,"PHP fixture BUY");
   GLCheck(signal.decision_utc==D'2026.11.02 12:15:00',"Decision is M5 close");
   GLNear(signal.entry,2003.6313,"PHP golden entry");
   GLNear(signal.stop,1997.6299,"PHP golden stop");
   GLNear(signal.target,2012.6335,"PHP golden full TP1");
   GLNear(signal.risk,6.0015,"PHP golden risk");
   GLNear(signal.atr,2.6235,"PHP golden simple ATR14");
   GLNear(signal.rsi,55.12,"PHP golden simple RSI14");
   GLNear(signal.fast,2001.4184,"PHP golden SMA9");
   GLNear(signal.slow,2001.1347,"PHP golden SMA21");
   GLNear(signal.trend_now,2000.97092221,"PHP golden SMA200");
   GLNear(signal.trend_prior,2000.90872890,"PHP golden three-active-bar slope");
   GLCheck(GLSynthetic(bars,D'2026.11.02 12:15:00',true),"Build mirrored fixture");
   GLCheck(GLEvaluate(bars,signal,reason) && signal.side==-1,"Mirrored SELL is symmetric");
   GLNear(signal.entry,1996.3687,"Mirrored entry");
   GLNear(signal.stop,2002.3701,"Mirrored stop");
   GLNear(signal.target,1987.3665,"Mirrored TP1");
   GLCheck(GLSynthetic(bars,D'2026.11.02 12:15:00',false,true),"Build countertrend fixture");
   GLCheck(GLEvaluate(bars,signal,reason) && signal.side==0 && signal.entry==0.0,"Countertrend crossover HOLD clears plan");
   GLCheck(GLSynthetic(bars,D'2026.11.02 17:00:00'),"Build session-end fixture");
   GLCheck(GLEvaluate(bars,signal,reason) && signal.side==0,"Candle closing17UTC is excluded");
   GLCheck(GLSynthetic(bars,D'2026.11.02 07:00:00'),"Build session-start fixture");
   GLCheck(GLEvaluate(bars,signal,reason) && signal.side==1,"Candle closing07UTC is included");
   for(int i=0;i<GL_REQUIRED_BARS;i++)
     {
      bars[i].open=2000.0;
      bars[i].high=2000.0;
      bars[i].low=2000.0;
      bars[i].close=2000.0;
     }
   GLCheck(GLEvaluate(bars,signal,reason) && signal.side==0,"Flat prices do not invent an entry");
   GLNear(signal.rsi,100.0,"Flat RSI matches legacy zero-loss convention");
   GLNear(signal.atr,0.5,"Flat ATR matches legacy fallback");
   bars[100].high=1.0;
   GLCheck(!GLEvaluate(bars,signal,reason) && signal.side==0,"Invalid OHLC fails closed");
   GLSynthetic(bars,D'2026.11.02 12:15:00');
   bars[100].time=bars[99].time;
   GLCheck(!GLEvaluate(bars,signal,reason),"Duplicate times fail closed");
   GLSynthetic(bars,D'2026.11.02 12:15:00');
   bars[202].time=(datetime)((long)bars[202].time+60);
   GLCheck(!GLEvaluate(bars,signal,reason),"Misaligned M5 open fails closed");
   GLSynthetic(bars,D'2026.11.02 12:15:00');
   ArraySetAsSeries(bars,true);
   GLCheck(!GLEvaluate(bars,signal,reason),"AS_SERIES input fails closed");
   ArraySetAsSeries(bars,false);
   ArrayResize(bars,202);
   GLCheck(!GLEvaluate(bars,signal,reason),"Insufficient history fails closed");
   ArrayResize(bars,204);
   GLCheck(!GLEvaluate(bars,signal,reason),"Extra or future bars must be selected by caller");
  }

void OnStart()
  {
   GLTestCalendar();
   GLTestMath();
   PrintFormat("GoldLogic pure core self-test: %s | checks=%d failures=%d",gl_failures==0 ? "PASS" : "FAIL",gl_checks,gl_failures);
  }
