import { Controller } from '@hotwired/stimulus';
import { useTransition } from 'stimulus-use';
export default class extends Controller {
    static targets = ['timer']
    static values = {
        timeout: Number,
        progress: {
            type: Number,
            default: 100
        }
    };
    connect() {
        useTransition(this, {
            leaveActive: 'transition-all',
            leaveFrom: 'translate-y-0 opacity-100',
            leaveTo: 'translate-y-full opacity-0',
            transitioned: true,
        });
        if (this.timeoutValue) {
            if (this.hasTimerTarget) {
                let duration = this.timeoutValue/10000
                this.timerTarget.style = '--transition-duration: '+duration+'s;'
                let self = this;
                let progressInterval = setInterval(function() { self.decrement(self, progressInterval); }, (this.timeoutValue/10));
            }
        }
    }
    decrement(self, progressInterval){
        if(self.progressValue){
            self.progressValue -= 10;
        } else {
            clearInterval(progressInterval);
            this.close();
        }
    }
    progressValueChanged(){
        if (this.hasTimerTarget) {
            this.timerTarget.value = this.progressValue
        }
    }
    close() {
        this.leave();
    }
}
